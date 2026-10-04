<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedAssignment;
use App\Models\BedReservation;
use App\Models\PatientTransfer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BedManagementService
{
    private const ALLOWED_STATUS_TRANSITIONS = [
        Bed::STATUS_AVAILABLE => [Bed::STATUS_AVAILABLE, Bed::STATUS_CLEANING, Bed::STATUS_MAINTENANCE, Bed::STATUS_BLOCKED],
        Bed::STATUS_OCCUPIED => [Bed::STATUS_OCCUPIED, Bed::STATUS_CLEANING, Bed::STATUS_MAINTENANCE, Bed::STATUS_BLOCKED],
        Bed::STATUS_RESERVED => [Bed::STATUS_RESERVED, Bed::STATUS_AVAILABLE, Bed::STATUS_CLEANING, Bed::STATUS_MAINTENANCE, Bed::STATUS_BLOCKED],
        Bed::STATUS_CLEANING => [Bed::STATUS_AVAILABLE, Bed::STATUS_CLEANING, Bed::STATUS_MAINTENANCE, Bed::STATUS_BLOCKED],
        Bed::STATUS_MAINTENANCE => [Bed::STATUS_AVAILABLE, Bed::STATUS_CLEANING, Bed::STATUS_MAINTENANCE, Bed::STATUS_BLOCKED],
        Bed::STATUS_BLOCKED => [Bed::STATUS_AVAILABLE, Bed::STATUS_CLEANING, Bed::STATUS_MAINTENANCE, Bed::STATUS_BLOCKED],
    ];

    public function __construct(protected AuditLogService $audit) {}

    /**
     * Get available beds, optionally filtered by ward.
     */
    public function availableBeds(?int $wardId = null): Collection
    {
        $query = Bed::where('status', Bed::STATUS_AVAILABLE)
            ->with(['room.ward']);

        if ($wardId) {
            $query->whereHas('room', fn ($q) => $q->where('ward_id', $wardId));
        }

        return $query->orderBy('room_id')->get();
    }

    public function assignableBeds(Admission $admission, ?int $wardId = null): Collection
    {
        $query = Bed::query()
            ->with(['room.ward', 'activeReservation'])
            ->where(function ($beds) use ($admission): void {
                $beds->where('status', Bed::STATUS_AVAILABLE)
                    ->orWhere(function ($reserved) use ($admission): void {
                        $reserved->where('status', Bed::STATUS_RESERVED)
                            ->whereHas('activeReservation', function ($reservation) use ($admission): void {
                                $reservation->where('admission_id', $admission->id)
                                    ->where(function ($expiry): void {
                                        $expiry->where('expires_at', '>', now());
                                    });
                            });
                    });
            });

        if ($wardId) {
            $query->whereHas('room', fn ($q) => $q->where('ward_id', $wardId));
        }

        return $query->orderBy('room_id')->get();
    }

    /**
     * Reserve a bed for an admission. Transactional with locking.
     */
    public function reserveBed(Admission $admission, int $bedId, ?int $expiresInMinutes = null): BedReservation
    {
        return DB::transaction(function () use ($admission, $bedId, $expiresInMinutes): BedReservation {
            $lockedAdmission = Admission::query()->lockForUpdate()->find($admission->id);
            if (! $lockedAdmission) {
                throw ValidationException::withMessages(['admission_id' => ['Admission not found.']]);
            }

            $existingReservations = BedReservation::query()
                ->where('admission_id', $lockedAdmission->id)
                ->where('status', BedReservation::STATUS_ACTIVE)
                ->get();
            $bedIds = $existingReservations->pluck('bed_id')->push($bedId)->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $beds = Bed::query()->whereIn('id', $bedIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $bed = $beds->get($bedId);
            if (! $bed) {
                throw ValidationException::withMessages([
                    'bed_id' => 'This bed is no longer available.',
                ]);
            }

            foreach ($beds as $lockedBed) {
                $this->expireActiveReservationsForBed($lockedBed);
            }

            $reservationsToCancel = BedReservation::query()
                ->where('admission_id', $lockedAdmission->id)
                ->where('status', BedReservation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get();
            $activeAssignment = BedAssignment::query()
                ->where('bed_id', $bed->id)
                ->where('status', BedAssignment::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();
            $activeReservation = BedReservation::query()
                ->where('bed_id', $bed->id)
                ->where('status', BedReservation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($bed->status !== Bed::STATUS_AVAILABLE || $activeAssignment || $activeReservation) {
                throw ValidationException::withMessages([
                    'bed_id' => ['This bed has an active assignment or reservation and cannot be reserved.'],
                ]);
            }

            foreach ($reservationsToCancel as $oldReservation) {
                $oldReservation->update(['status' => BedReservation::STATUS_CANCELLED]);
                $oldBed = $beds->get($oldReservation->bed_id);
                if ($oldBed && (int) $oldBed->id !== (int) $bed->id) {
                    $this->makeAvailableIfUnoccupied($oldBed);
                }
            }

            $reservation = BedReservation::create([
                'bed_id' => $bed->id,
                'admission_id' => $lockedAdmission->id,
                'reserved_by' => auth()->id(),
                'expires_at' => now()->addMinutes(max(1, $expiresInMinutes ?? (int) config('hims.reservation_default_minutes', 120))),
                'status' => BedReservation::STATUS_ACTIVE,
            ]);

            $bed->update(['status' => Bed::STATUS_RESERVED, 'status_updated_at' => now()]);

            $this->audit->log('RESERVE_BED', 'bed', $bed->id, 'SUCCESS', ['admission_id' => $lockedAdmission->id]);

            return $reservation;
        });
    }

    public function cancelReservation(int $reservationId, ?int $bedId = null, ?int $admissionId = null): BedReservation
    {
        return DB::transaction(function () use ($reservationId, $bedId, $admissionId): BedReservation {
            $reservation = BedReservation::query()->find($reservationId);
            if (
                ! $reservation
                || ($bedId !== null && (int) $reservation->bed_id !== $bedId)
                || ($admissionId !== null && (int) $reservation->admission_id !== $admissionId)
            ) {
                throw ValidationException::withMessages([
                    'reservation_id' => ['The active reservation could not be found for this bed or admission.'],
                ]);
            }

            $bed = Bed::query()->lockForUpdate()->find($reservation->bed_id);
            if (! $bed) {
                throw ValidationException::withMessages(['bed_id' => ['The reservation bed could not be found.']]);
            }

            $reservation = BedReservation::query()->lockForUpdate()->find($reservationId);
            if (
                ! $reservation
                || $reservation->status !== BedReservation::STATUS_ACTIVE
                || (int) $reservation->bed_id !== (int) $bed->id
                || ($admissionId !== null && (int) $reservation->admission_id !== $admissionId)
            ) {
                throw ValidationException::withMessages([
                    'reservation_id' => ['The active reservation could not be found for this bed or admission.'],
                ]);
            }

            $reservation->update(['status' => BedReservation::STATUS_CANCELLED]);
            $this->makeAvailableIfUnoccupied($bed);

            return $reservation->fresh();
        });
    }

    /**
     * Assign a bed to an admission. Transactional with row locking to prevent concurrency.
     */
    public function assignBed(Admission $admission, int $bedId, ?int $reservationId = null): BedAssignment
    {
        $this->assertReservationNotExpired($admission, $bedId, $reservationId);

        return DB::transaction(function () use ($admission, $bedId, $reservationId) {
            $lockedAdmission = Admission::query()->lockForUpdate()->find($admission->id);
            if (! $lockedAdmission) {
                throw ValidationException::withMessages(['admission_id' => ['Admission not found.']]);
            }

            $existingAssignments = BedAssignment::query()
                ->where('admission_id', $lockedAdmission->id)
                ->where('status', BedAssignment::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get();
            $bedIds = $existingAssignments->pluck('bed_id')->push($bedId)->unique()->sort()->values();
            $beds = Bed::query()->whereIn('id', $bedIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $bed = $beds->get($bedId);
            if (! $bed) {
                throw ValidationException::withMessages(['bed_id' => ['Bed not found.']]);
            }

            $this->expireActiveReservationsForBed($bed);
            $activeReservations = BedReservation::query()
                ->where('bed_id', $bed->id)
                ->where('status', BedReservation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get();
            $activeBedAssignment = BedAssignment::query()
                ->where('bed_id', $bed->id)
                ->where('status', BedAssignment::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();
            $reservation = $reservationId
                ? BedReservation::query()->lockForUpdate()->find($reservationId)
                : null;

            if ($activeBedAssignment) {
                throw ValidationException::withMessages([
                    'bed_id' => ['This bed already has an active patient assignment.'],
                ]);
            }

            if ($reservationId && (
                ! $reservation
                || (int) $reservation->admission_id !== (int) $lockedAdmission->id
                || (int) $reservation->bed_id !== (int) $bed->id
                || $reservation->status !== BedReservation::STATUS_ACTIVE
                || ! $reservation->expires_at
                || $reservation->expires_at->lte(now())
            )) {
                throw ValidationException::withMessages([
                    'reservation_id' => ['The reservation is invalid, expired, or belongs to another admission or bed.'],
                ]);
            }

            if ($activeReservations->isNotEmpty() && ! $reservation) {
                throw ValidationException::withMessages([
                    'reservation_id' => ['This bed has an active reservation and requires its matching admission reservation.'],
                ]);
            }

            if ($bed->status === Bed::STATUS_RESERVED && ! $reservation) {
                throw ValidationException::withMessages([
                    'reservation_id' => ['A reserved bed requires an active reservation for this admission.'],
                ]);
            }

            if ($bed->status !== Bed::STATUS_AVAILABLE && $bed->status !== Bed::STATUS_RESERVED) {
                throw ValidationException::withMessages([
                    'bed_id' => ["This bed is not assignable (current status: {$bed->status})."],
                ]);
            }

            if ($activeReservations->isNotEmpty() && $bed->status !== Bed::STATUS_RESERVED) {
                throw ValidationException::withMessages([
                    'bed_id' => ['The bed status conflicts with its active reservation.'],
                ]);
            }

            if ($existingAssignments->contains('bed_id', $bed->id)) {
                throw ValidationException::withMessages([
                    'bed_id' => ['This admission is already assigned to the selected bed.'],
                ]);
            }

            if ($reservation) {
                $reservation->update(['status' => BedReservation::STATUS_CONVERTED]);
            }

            foreach ($existingAssignments as $existingAssignment) {
                $existingAssignment->update([
                    'status' => BedAssignment::STATUS_RELEASED,
                    'released_at' => now(),
                ]);
                $previousBed = $beds->get($existingAssignment->bed_id);
                if ($previousBed) {
                    BedReservation::query()
                        ->where('bed_id', $previousBed->id)
                        ->where('status', BedReservation::STATUS_ACTIVE)
                        ->update(['status' => BedReservation::STATUS_CANCELLED]);
                    $previousBed->update([
                        'status' => Bed::STATUS_CLEANING,
                        'status_updated_at' => now(),
                    ]);
                }
            }

            $assignment = BedAssignment::create([
                'admission_id' => $admission->id,
                'bed_id' => $bed->id,
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
                'status' => BedAssignment::STATUS_ACTIVE,
            ]);

            $bed->update(['status' => Bed::STATUS_OCCUPIED, 'status_updated_at' => now()]);

            $this->audit->assignBed($admission->id, $bed->id);

            return $assignment;
        });
    }

    public function assertReservationNotExpired(Admission $admission, int $bedId, ?int $reservationId): void
    {
        if (! $reservationId) {
            return;
        }

        $expired = DB::transaction(function () use ($reservationId, $admission, $bedId): bool {
            $unlockedReservation = BedReservation::query()->find($reservationId);
            if (! $unlockedReservation) {
                return false;
            }

            $bed = Bed::query()->lockForUpdate()->find($unlockedReservation->bed_id);
            $reservation = BedReservation::query()->lockForUpdate()->find($reservationId);

            if (
                $reservation
                && (int) $reservation->admission_id === (int) $admission->id
                && (int) $reservation->bed_id === $bedId
                && $reservation->status === BedReservation::STATUS_ACTIVE
                && (! $reservation->expires_at || $reservation->expires_at->lte(now()))
            ) {
                if ($bed) {
                    $this->expireActiveReservationsForBed($bed);
                } else {
                    $reservation->update(['status' => BedReservation::STATUS_EXPIRED]);
                }

                return true;
            }

            return false;
        });

        if ($expired) {
            throw ValidationException::withMessages([
                'reservation_id' => ['This bed reservation has expired. Reserve the bed again before assigning it.'],
            ]);
        }
    }

    private function expireActiveReservationsForBed(Bed $bed): void
    {
        $expiredReservations = BedReservation::query()
            ->where('bed_id', $bed->id)
            ->where('status', BedReservation::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '<=', now());
            })
            ->lockForUpdate()
            ->get();

        foreach ($expiredReservations as $reservation) {
            $reservation->update(['status' => BedReservation::STATUS_EXPIRED]);
        }

        if ($expiredReservations->isNotEmpty()) {
            $this->makeAvailableIfUnoccupied($bed);
        }
    }

    private function makeAvailableIfUnoccupied(Bed $bed): void
    {
        $activeAssignment = BedAssignment::query()
            ->where('bed_id', $bed->id)
            ->where('status', BedAssignment::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();
        $activeReservation = BedReservation::query()
            ->where('bed_id', $bed->id)
            ->where('status', BedReservation::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();

        if ($bed->status === Bed::STATUS_RESERVED && ! $activeAssignment && ! $activeReservation) {
            $bed->update(['status' => Bed::STATUS_AVAILABLE, 'status_updated_at' => now()]);
        }
    }

    /**
     * Transfer a patient to a new bed. Transactional.
     */
    public function transfer(Admission $admission, int $toBedId, ?string $reason = null): PatientTransfer
    {
        return DB::transaction(function () use ($admission, $toBedId, $reason) {
            $lockedAdmission = Admission::whereKey($admission->id)->lockForUpdate()->first();

            if (! $lockedAdmission) {
                throw ValidationException::withMessages(['admission_id' => 'Admission not found.']);
            }

            $activeAssignment = BedAssignment::where('admission_id', $lockedAdmission->id)
                ->where('status', BedAssignment::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if (! $activeAssignment) {
                throw ValidationException::withMessages(['admission_id' => 'Patient has no active bed assignment.']);
            }

            if ((int) $activeAssignment->bed_id === $toBedId) {
                throw ValidationException::withMessages(['destination_bed_id' => 'The destination bed must differ from the current bed.']);
            }

            $beds = Bed::whereIn('id', [$activeAssignment->bed_id, $toBedId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $toBed = $beds->get($toBedId);
            if (! $toBed) {
                throw ValidationException::withMessages([
                    'destination_bed_id' => 'Destination bed not found.',
                ]);
            }

            if ($toBed->status !== Bed::STATUS_AVAILABLE) {
                throw ValidationException::withMessages([
                    'destination_bed_id' => "Destination bed is not available (current status: {$toBed->status}).",
                ]);
            }

            $fromBed = $beds->get($activeAssignment->bed_id);
            if (! $fromBed) {
                throw ValidationException::withMessages([
                    'admission_id' => 'The active bed assignment references a bed that no longer exists.',
                ]);
            }

            if ($fromBed->status !== Bed::STATUS_OCCUPIED) {
                throw ValidationException::withMessages([
                    'admission_id' => 'The current bed is not marked as occupied.',
                ]);
            }

            $fromBedId = $fromBed->id;
            $activeAssignment->update(['status' => BedAssignment::STATUS_RELEASED, 'released_at' => now()]);
            $fromBed->update(['status' => Bed::STATUS_CLEANING, 'status_updated_at' => now()]);

            BedAssignment::create([
                'admission_id' => $lockedAdmission->id,
                'bed_id' => $toBed->id,
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
                'status' => BedAssignment::STATUS_ACTIVE,
            ]);

            $toBed->update(['status' => Bed::STATUS_OCCUPIED, 'status_updated_at' => now()]);

            $transfer = PatientTransfer::create([
                'admission_id' => $lockedAdmission->id,
                'from_bed_id' => $fromBedId,
                'to_bed_id' => $toBed->id,
                'transferred_by' => auth()->id(),
                'transferred_at' => now(),
                'reason' => $reason,
            ]);

            $lockedAdmission->update(['status' => Admission::STATUS_TRANSFERRED]);

            $this->audit->transferPatient(
                $lockedAdmission->id,
                $fromBedId,
                $toBed->id,
                $transfer->id,
                $reason
            );

            return $transfer;
        });
    }

    /**
     * Release a bed and mark it for cleaning. Transactional.
     */
    public function releaseBed(Admission $admission, int $bedId): Bed
    {
        return DB::transaction(function () use ($admission, $bedId) {
            $bed = Bed::where('id', $bedId)->lockForUpdate()->first();

            if (! $bed) {
                throw ValidationException::withMessages(['bed_id' => 'Bed not found.']);
            }

            $assignment = BedAssignment::where('admission_id', $admission->id)
                ->where('bed_id', $bed->id)
                ->where('status', BedAssignment::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if (! $assignment) {
                throw ValidationException::withMessages([
                    'admission_id' => 'The admission does not have an active assignment for this bed.',
                ]);
            }

            $assignment->update(['status' => BedAssignment::STATUS_RELEASED, 'released_at' => now()]);

            // Cancel any active reservations
            BedReservation::where('bed_id', $bed->id)
                ->where('status', BedReservation::STATUS_ACTIVE)
                ->update(['status' => BedReservation::STATUS_CANCELLED]);

            $bed->update(['status' => Bed::STATUS_CLEANING, 'status_updated_at' => now()]);

            $this->audit->log('RELEASE_BED', 'bed', $bed->id, 'SUCCESS', ['admission_id' => $admission->id]);

            return $bed;
        });
    }

    /**
     * Mark a cleaning bed as available, or set maintenance/blocked status.
     */
    public function setStatus(Bed $bed, string $status): Bed
    {
        return DB::transaction(function () use ($bed, $status): Bed {
            $lockedBed = Bed::query()->lockForUpdate()->find($bed->id);
            if (! $lockedBed) {
                throw ValidationException::withMessages(['bed_id' => ['Bed not found.']]);
            }

            $this->expireActiveReservationsForBed($lockedBed);

            $hasActiveAssignment = BedAssignment::query()
                ->where('bed_id', $lockedBed->id)
                ->where('status', BedAssignment::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();
            $hasActiveReservation = BedReservation::query()
                ->where('bed_id', $lockedBed->id)
                ->where('status', BedReservation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($hasActiveAssignment && $hasActiveReservation) {
                throw ValidationException::withMessages([
                    'status' => ['The bed has conflicting active assignment and reservation records.'],
                ]);
            }

            if ($hasActiveAssignment && $status !== Bed::STATUS_OCCUPIED) {
                throw ValidationException::withMessages([
                    'status' => ['A bed with an active assignment must remain Occupied until its assignment is released.'],
                ]);
            }

            if ($hasActiveReservation && $status !== Bed::STATUS_RESERVED) {
                throw ValidationException::withMessages([
                    'status' => ['A bed with an active reservation must remain Reserved until the reservation is converted or cancelled.'],
                ]);
            }

            if (! $hasActiveAssignment && $status === Bed::STATUS_OCCUPIED) {
                throw ValidationException::withMessages([
                    'status' => ['A bed cannot be marked Occupied without an active patient assignment.'],
                ]);
            }

            if (! $hasActiveReservation && $status === Bed::STATUS_RESERVED) {
                throw ValidationException::withMessages([
                    'status' => ['A bed cannot be marked Reserved without an active reservation.'],
                ]);
            }

            if (! in_array($status, self::ALLOWED_STATUS_TRANSITIONS[$lockedBed->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => ["The bed cannot transition from {$lockedBed->status} to {$status}."],
                ]);
            }

            $lockedBed->update(['status' => $status, 'status_updated_at' => now()]);
            $this->audit->log('SET_BED_STATUS', 'bed', $lockedBed->id, 'SUCCESS', ['status' => $status]);

            return $lockedBed;
        });
    }
}
