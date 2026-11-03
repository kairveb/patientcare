<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\AppointmentSlot;
use App\Models\Patient;
use App\Models\Waitlist;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function __construct(
        protected SchedulingService $scheduling,
        protected AuditLogService $audit,
        protected TelehealthService $telehealth,
        protected QueueDisplayService $queueDisplay,
    ) {
    }

    /**
     * Generate the next appointment number.
     */
    public function generateNumber(): string
    {
        $year = now()->format('Y');
        // Only consider well-formed sequential numbers; other formats (e.g. telehealth-specific numbers)
        // would otherwise get picked up by string ordering and break the numeric parse below.
        $candidates = Appointment::where('appointment_number', 'like', "APT-{$year}-%")
            ->lockForUpdate()
            ->pluck('appointment_number');

        $maxSequence = 0;
        foreach ($candidates as $number) {
            if (preg_match('/^APT-' . $year . '-(\d{6})$/', $number, $matches)) {
                $maxSequence = max($maxSequence, (int) $matches[1]);
            }
        }

        return sprintf('APT-%s-%06d', $year, $maxSequence + 1);
    }

    /**
     * Book an appointment with double-booking prevention.
     */
    public function book(array $data, ?int $userId = null): Appointment
    {
        if (Patient::withTrashed()
            ->whereKey($data['patient_id'])
            ->where('is_temporary_identity', true)
            ->exists()) {
            throw ValidationException::withMessages([
                'patient_id' => ['Temporary ER identities must be completed before booking an appointment.'],
            ]);
        }

        return DB::transaction(function () use ($data, $userId) {
            if (empty($data['appointment_slot_id'])) {
                throw ValidationException::withMessages([
                    'appointment_slot_id' => 'Select an available appointment slot.',
                ]);
            }

            $providerId = (int) $data['provider_id'];
            $this->scheduling->lockProvider($providerId);

            $startsAt = Carbon::parse($data['starts_at']);
            $duration = isset($data['duration']) ? (int) $data['duration'] : null;
            $slot = $this->scheduling->reserveSlot(
                (int) $data['appointment_slot_id'],
                $providerId,
                $startsAt,
                $duration
            );
            $startsAt = $slot->starts_at;
            $endsAt = $slot->ends_at;

            $this->ensureNoConflict($providerId, $startsAt, $endsAt);

            $appointment = $this->createAppointmentRecord($data, $startsAt, $endsAt, $userId);
            $this->recordInitialStatusHistory($appointment, $userId);
            $this->provisionTelehealthIfNeeded($appointment);
            $this->audit->createAppointment($appointment->id, ['appointment_number' => $appointment->appointment_number]);

            return $appointment;
        });
    }

    protected function ensureNoConflict(int $providerId, Carbon $startsAt, Carbon $endsAt, ?int $ignoreAppointmentId = null): void
    {
        if ($this->scheduling->providerHasConflict($providerId, $startsAt, $endsAt, $ignoreAppointmentId)) {
            throw ValidationException::withMessages([
                'starts_at' => 'This provider already has an appointment that conflicts with the selected time.',
            ]);
        }
    }

    protected function createAppointmentRecord(array $data, Carbon $startsAt, Carbon $endsAt, ?int $userId): Appointment
    {
        return Appointment::create([
            'appointment_number' => $this->generateNumber(),
            'patient_id' => $data['patient_id'],
            'provider_id' => $data['provider_id'],
            'department_id' => $data['department_id'] ?? null,
            'appointment_type_id' => $data['appointment_type_id'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $data['status'] ?? Appointment::STATUS_PENDING,
            'reason' => $data['reason'] ?? null,
            'created_by' => $userId,
            'booking_source' => $data['booking_source'] ?? 'online',
            'parent_appointment_id' => $data['parent_appointment_id'] ?? null,
        ]);
    }

    protected function recordInitialStatusHistory(Appointment $appointment, ?int $userId): void
    {
        AppointmentStatusHistory::create([
            'appointment_id' => $appointment->id,
            'from_status' => null,
            'to_status' => $appointment->status,
            'changed_by' => $userId,
            'reason' => 'Appointment booked',
        ]);
    }

    protected function provisionTelehealthIfNeeded(Appointment $appointment): void
    {
        if ($appointment->appointmentType?->telehealth) {
            $this->telehealth->createSession($appointment);
        }
    }

    /**
     * Transition an appointment to a new status with validation.
     */
    public function transitionStatus(Appointment $appointment, string $toStatus, ?int $userId = null, ?string $reason = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $toStatus, $userId, $reason) {
            $lockedAppointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            if (! in_array($toStatus, $this->allowedTransitions($lockedAppointment->status), true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot transition appointment from {$lockedAppointment->status} to {$toStatus}.",
                ]);
            }

            $from = $lockedAppointment->status;
            $lockedAppointment->update(['status' => $toStatus]);

            if ($toStatus === Appointment::STATUS_CONFIRMED && $lockedAppointment->appointmentType?->telehealth) {
                $this->telehealth->createSession($lockedAppointment);
            }

            if (in_array($toStatus, [Appointment::STATUS_CANCELLED, Appointment::STATUS_REJECTED], true) && $lockedAppointment->telehealthSession) {
                $this->telehealth->cancel($lockedAppointment->telehealthSession);
            }

            AppointmentStatusHistory::create([
                'appointment_id' => $lockedAppointment->id,
                'from_status' => $from,
                'to_status' => $toStatus,
                'changed_by' => $userId,
                'reason' => $reason,
            ]);

            $this->queueDisplay->syncAppointment($lockedAppointment);

            if (in_array($toStatus, [Appointment::STATUS_CANCELLED, Appointment::STATUS_REJECTED, Appointment::STATUS_NO_SHOW], true)) {
                $this->releaseSlot($lockedAppointment);
            }

            return $lockedAppointment->fresh();
        });
    }

    public function checkIn(Appointment $appointment, ?int $userId = null): Appointment
    {
        return $this->transitionStatus($appointment, Appointment::STATUS_CHECKED_IN, $userId, 'Patient checked in');
    }

    public function syncQueueDisplay(Appointment $appointment): void
    {
        $this->queueDisplay->syncAppointment($appointment);
    }

    public function cancel(Appointment $appointment, ?int $userId = null, ?string $reason = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $userId, $reason): Appointment {
            $this->scheduling->lockProvider((int) $appointment->provider_id);
            $lockedAppointment = Appointment::query()
                ->whereKey($appointment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $cancelledAppointment = $this->transitionStatus(
                $lockedAppointment,
                Appointment::STATUS_CANCELLED,
                $userId,
                $reason ?? 'Cancelled'
            );

            $this->audit->cancelAppointment($cancelledAppointment->id, ['reason' => $reason]);

            return $cancelledAppointment;
        });
    }

    public function markNoShow(Appointment $appointment, ?int $userId = null): Appointment
    {
        return $this->transitionStatus($appointment, Appointment::STATUS_NO_SHOW, $userId, 'Patient did not show');
    }

    /**
     * Reschedule an appointment to a new time, preventing conflicts.
     */
    public function reschedule(Appointment $appointment, array $data, ?int $userId = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $data, $userId) {
            if (empty($data['appointment_slot_id'])) {
                throw ValidationException::withMessages([
                    'appointment_slot_id' => 'Select an available appointment slot.',
                ]);
            }

            $this->scheduling->lockProvider((int) $appointment->provider_id);
            $lockedAppointment = Appointment::query()
                ->whereKey($appointment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $startsAt = Carbon::parse($data['starts_at']);
            $duration = isset($data['duration']) ? (int) $data['duration'] : null;
            $slot = $this->scheduling->reserveSlot(
                (int) $data['appointment_slot_id'],
                (int) $lockedAppointment->provider_id,
                $startsAt,
                $duration
            );
            $startsAt = $slot->starts_at;
            $endsAt = $slot->ends_at;

            $this->ensureNoConflict(
                (int) $lockedAppointment->provider_id,
                $startsAt,
                $endsAt,
                (int) $lockedAppointment->id
            );

            $previousStatus = $lockedAppointment->status;
            $this->releaseSlot($lockedAppointment);
            $lockedAppointment->update([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => Appointment::STATUS_CONFIRMED,
                'reminder_24h_sent_at' => null,
                'reminder_1h_sent_at' => null,
            ]);

            AppointmentStatusHistory::create([
                'appointment_id' => $lockedAppointment->id,
                'from_status' => $previousStatus,
                'to_status' => Appointment::STATUS_CONFIRMED,
                'changed_by' => $userId,
                'reason' => 'Rescheduled',
            ]);

            $this->audit->rescheduleAppointment($lockedAppointment->id);

            return $lockedAppointment;
        });
    }

    public function reassignProvider(
        Appointment $appointment,
        int $providerId,
        int $slotId,
        Carbon $startsAt,
        ?int $userId = null,
        ?string $reason = null
    ): Appointment {
        return DB::transaction(function () use ($appointment, $providerId, $slotId, $startsAt, $userId, $reason): Appointment {
            $providerIds = [(int) $appointment->provider_id, $providerId];
            sort($providerIds);
            foreach (array_unique($providerIds) as $id) {
                $this->scheduling->lockProvider($id);
            }
            if (! \App\Models\Provider::query()->whereKey($providerId)->where('active', true)->exists()) {
                throw ValidationException::withMessages([
                    'provider_id' => 'The selected replacement doctor is not active.',
                ]);
            }

            $lockedAppointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            if (! in_array($lockedAppointment->status, [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED], true)) {
                throw ValidationException::withMessages([
                    'appointment' => 'Only pending or confirmed appointments can be reassigned.',
                ]);
            }

            $slot = $this->scheduling->reserveSlot($slotId, $providerId, $startsAt);
            $this->ensureNoConflict($providerId, $slot->starts_at, $slot->ends_at, (int) $lockedAppointment->id);

            $fromStatus = $lockedAppointment->status;
            $oldProviderId = (int) $lockedAppointment->provider_id;
            $providerDepartmentId = \App\Models\Provider::query()->findOrFail($providerId)->department_id;
            $this->releaseSlot($lockedAppointment);
            $lockedAppointment->update([
                'provider_id' => $providerId,
                'department_id' => $providerDepartmentId ?? $lockedAppointment->department_id,
                'starts_at' => $slot->starts_at,
                'ends_at' => $slot->ends_at,
                'status' => Appointment::STATUS_CONFIRMED,
                'reminder_24h_sent_at' => null,
                'reminder_1h_sent_at' => null,
            ]);

            AppointmentStatusHistory::create([
                'appointment_id' => $lockedAppointment->id,
                'from_status' => $fromStatus,
                'to_status' => Appointment::STATUS_CONFIRMED,
                'changed_by' => $userId,
                'reason' => $reason ?? 'Provider reassigned due to unavailability',
            ]);

            $session = $lockedAppointment->telehealthSession()->lockForUpdate()->first();
            if ($session) {
                $session->update([
                    'start_time' => $slot->starts_at,
                    'duration' => (int) $slot->starts_at->diffInMinutes($slot->ends_at),
                    'status' => \App\Models\TelehealthSession::STATUS_SCHEDULED,
                ]);
                if (str_contains((string) $session->join_url, '/telehealth/')) {
                    $joinUrl = url('/telehealth/'.$session->id.'/join?token='.rawurlencode($session->fresh()->secureJoinToken()));
                    $session->update(['join_url' => $joinUrl, 'host_start_url' => $joinUrl]);
                }
            }

            $this->audit->log('REASSIGN_APPOINTMENT_PROVIDER', 'appointment', $lockedAppointment->id, 'SUCCESS', [
                'original_provider_id' => $oldProviderId,
                'new_provider_id' => $providerId,
                'appointment_slot_id' => $slot->id,
            ], $userId);

            return $lockedAppointment->fresh(['provider', 'telehealthSession']);
        });
    }

    /**
     * Release the appointment slot if one was claimed.
     */
    protected function releaseSlot(Appointment $appointment): void
    {
        $startsAt = Carbon::parse($appointment->starts_at);
        $endsAt = Carbon::parse($appointment->ends_at);
        $status = $this->scheduling->providerIsUnavailable((int) $appointment->provider_id, $startsAt, $endsAt)
            ? AppointmentSlot::STATUS_BLOCKED
            : AppointmentSlot::STATUS_AVAILABLE;

        AppointmentSlot::where('provider_id', $appointment->provider_id)
            ->where('starts_at', $appointment->starts_at)
            ->where('status', AppointmentSlot::STATUS_BOOKED)
            ->update(['status' => $status]);

        if ($status === AppointmentSlot::STATUS_AVAILABLE) {
            $slot = AppointmentSlot::query()
                ->where('provider_id', $appointment->provider_id)
                ->where('starts_at', $appointment->starts_at)
                ->where('status', AppointmentSlot::STATUS_AVAILABLE)
                ->first();
            if ($slot) {
                app(AppointmentWaitlistService::class)->offerMatchingPatients($slot);
            }
        }
    }

    /**
     * Add a patient to the waitlist.
     */
    public function addToWaitlist(array $data): Waitlist
    {
        return Waitlist::create($data);
    }

    protected function allowedTransitions(string $from): array
    {
        return match ($from) {
            Appointment::STATUS_PENDING => [Appointment::STATUS_CONFIRMED, Appointment::STATUS_REJECTED, Appointment::STATUS_CANCELLED],
            Appointment::STATUS_CONFIRMED => [Appointment::STATUS_CHECKED_IN, Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW],
            Appointment::STATUS_CHECKED_IN => [Appointment::STATUS_IN_CONSULTATION, Appointment::STATUS_CANCELLED],
            Appointment::STATUS_IN_CONSULTATION => [Appointment::STATUS_COMPLETED],
            Appointment::STATUS_COMPLETED => [],
            Appointment::STATUS_CANCELLED => [],
            Appointment::STATUS_REJECTED => [],
            Appointment::STATUS_NO_SHOW => [],
            default => [],
        };
    }
}
