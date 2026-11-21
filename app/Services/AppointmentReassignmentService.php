<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentReassignment;
use App\Models\AppointmentReassignmentHistory;
use App\Models\AppointmentSlot;
use App\Models\AppointmentType;
use App\Models\Provider;
use App\Models\ProviderUnavailability;
use App\Models\Waitlist;
use App\Notifications\AppointmentReassignmentNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentReassignmentService
{
    public function __construct(
        private readonly SchedulingService $scheduling,
        private readonly AppointmentService $appointments,
        private readonly AuditLogService $audit,
    ) {
    }

    public function markUnavailable(array $data, ?int $userId): ProviderUnavailability
    {
        [$unavailability, $cases] = DB::transaction(function () use ($data, $userId): array {
            $provider = Provider::query()->whereKey($data['provider_id'])->lockForUpdate()->firstOrFail();
            $startsAt = Carbon::parse($data['starts_at']);
            $endsAt = Carbon::parse($data['ends_at']);
            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                throw ValidationException::withMessages(['ends_at' => 'End time must be after start time.']);
            }

            $record = ProviderUnavailability::create([
                'provider_id' => $provider->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => $data['reason'],
                'details' => $data['details'] ?? null,
                'recorded_by' => $userId,
            ]);

            AppointmentSlot::query()
                ->where('provider_id', $provider->id)
                ->where('status', AppointmentSlot::STATUS_AVAILABLE)
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->update(['status' => AppointmentSlot::STATUS_BLOCKED]);

            $appointments = Appointment::query()
                ->with(['patient.user', 'provider', 'appointmentType'])
                ->where('provider_id', $provider->id)
                ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->orderBy('id')
                ->get();
            $cases = collect();
            foreach ($appointments as $appointment) {
                $case = AppointmentReassignment::create([
                    'appointment_id' => $appointment->id,
                    'provider_unavailability_id' => $record->id,
                    'original_provider_id' => $appointment->provider_id,
                    'status' => AppointmentReassignment::STATUS_PENDING_REVIEW,
                    'reason' => $record->reason,
                ]);
                $this->recordHistory($case, null, AppointmentReassignment::STATUS_PENDING_REVIEW, $userId, null, null, null, 'Reassignment case opened.');
                $this->audit->log('APPOINTMENT_REASSIGNMENT_OPENED', 'appointment_reassignment', $case->id, 'SUCCESS', [
                    'appointment_id' => $appointment->id,
                    'provider_unavailability_id' => $record->id,
                ], $userId);
                $case->setRelation('appointment', $appointment);
                $cases->push($case);
            }

            $this->audit->log('PROVIDER_UNAVAILABLE_MARKED', 'provider_unavailability', $record->id, 'SUCCESS', [
                'provider_id' => $provider->id,
                'reason' => $record->reason,
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $endsAt->toIso8601String(),
            ], $userId);

            return [$record, $cases];
        });

        foreach ($cases as $case) {
            $appointment = $case->appointment;
            $candidates = $this->suggestionsFor($appointment);
            $suggestion = $candidates->first();
            if ($suggestion) {
                $case->update([
                    'suggested_provider_id' => $suggestion['provider']->id,
                    'suggested_slot_id' => $suggestion['slot']->id,
                    'suggested_starts_at' => $suggestion['slot']->starts_at,
                ]);
                $this->audit->log('REASSIGNMENT_SUGGESTION_CREATED', 'appointment_reassignment', $case->id, 'SUCCESS', [
                    'suggested_provider_id' => $suggestion['provider']->id,
                    'appointment_slot_id' => $suggestion['slot']->id,
                    'starts_at' => $suggestion['slot']->starts_at->toIso8601String(),
                ], $userId);
            } else {
                $this->addCaseToWaitlist($case, $appointment, $userId);
            }
            $this->notifyParticipants($case->fresh(['appointment.patient.user', 'suggestedProvider.user']), $userId);
        }

        return $unavailability->fresh();
    }

    /**
     * Return one best available slot per provider, ordered by specialty/department,
     * same-time availability, nearest date, then lightest upcoming appointment load.
     *
     * @return Collection<int, array{provider: Provider, slot: AppointmentSlot, explanation: string, same_time: bool, load: int}>
     */
    public function suggestionsFor(Appointment $appointment): Collection
    {
        $appointment->loadMissing(['provider.specialties', 'appointmentType']);
        $original = $appointment->provider;
        $originalSpecialtyIds = $original?->specialties->pluck('id')->all() ?? [];
        $windowDays = (int) config('appointments.reassignment_window_days', 7);
        $windowEnd = $appointment->starts_at->copy()->addDays($windowDays);

        $providers = Provider::query()
            ->with(['user', 'department', 'specialties'])
            ->where('active', true)
            ->whereKeyNot($appointment->provider_id)
            ->get();
        $candidates = collect();

        foreach ($providers as $provider) {
            $matchingSpecialty = $provider->specialties->contains(fn ($specialty) => in_array($specialty->id, $originalSpecialtyIds, true));
            $sameDepartment = $provider->department_id !== null
                && $provider->department_id === $appointment->department_id;
            $ifType = $appointment->appointment_type_id;
            $slots = collect();
            $from = $appointment->starts_at->copy()->startOfDay();

            for ($date = $from->copy(); $date->lte($windowEnd); $date->addDay()) {
                $slots = $slots->merge($this->scheduling->availableSlots(
                    (int) $provider->id,
                    $date->toDateString()
                )->filter(function (AppointmentSlot $slot) use ($appointment, $ifType, $provider): bool {
                    return $slot->starts_at->gte(now())
                        && $slot->starts_at->gte($appointment->starts_at)
                        && $slot->starts_at->lte($appointment->starts_at->copy()->addDays((int) config('appointments.reassignment_window_days', 7)))
                        && ($slot->appointment_type_id === null || (int) $slot->appointment_type_id === (int) $ifType)
                        && $this->isCompatibleConsultation($appointment->appointmentType, $slot)
                        && ! $this->scheduling->providerHasConflict(
                            (int) $provider->id,
                            $slot->starts_at,
                            $slot->ends_at,
                            (int) $appointment->id
                        );
                }));
            }

            if ($slots->isEmpty()) {
                continue;
            }

            $slot = $slots->sortBy(fn (AppointmentSlot $candidate) => [
                $candidate->starts_at->equalTo($appointment->starts_at) ? 0 : 1,
                abs($candidate->starts_at->diffInMinutes($appointment->starts_at)),
                $candidate->starts_at->timestamp,
            ])->first();
            $load = Appointment::query()
                ->where('provider_id', $provider->id)
                ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
                ->whereBetween('starts_at', [$appointment->starts_at, $windowEnd])
                ->count();
            $sameTime = $slot->starts_at->equalTo($appointment->starts_at);

            $explanation = match (true) {
                $matchingSpecialty && $sameDepartment && $sameTime => 'Same specialty and department, with an open slot at the original time.',
                $matchingSpecialty && $sameTime => 'Same specialty, with an open slot at the original time.',
                $matchingSpecialty => 'Same specialty, with the nearest available appointment time.',
                $sameDepartment && $sameTime => 'Same department, with an open slot at the original time.',
                $sameDepartment => 'Same department, with the nearest available appointment time.',
                $sameTime => 'An available doctor can see the patient at the original time.',
                default => 'The nearest available appointment time within the search window.',
            };

            $candidates->push([
                'provider' => $provider,
                'slot' => $slot,
                'explanation' => $explanation,
                'same_time' => $sameTime,
                'load' => $load,
                'specialty_match' => $matchingSpecialty,
                'department_match' => $sameDepartment,
            ]);
        }

        return $candidates->sortBy(fn (array $item) => [
            $item['specialty_match'] ? 0 : ($item['department_match'] ? 1 : 2),
            $item['same_time'] ? 0 : 1,
            $item['slot']->starts_at->timestamp,
            $item['load'],
        ])->take((int) config('appointments.reassignment_candidate_limit', 5))->values();
    }

    public function decide(
        AppointmentReassignment $reassignment,
        string $action,
        int $userId,
        ?int $providerId = null,
        ?int $slotId = null,
        ?string $remarks = null
    ): AppointmentReassignment {
        $currentStatus = $reassignment->fresh()->status;
        if (! in_array($currentStatus, [
            AppointmentReassignment::STATUS_PENDING_REVIEW,
            AppointmentReassignment::STATUS_DECLINED_BY_PATIENT,
        ], true)) {
            throw ValidationException::withMessages(['case' => 'This reassignment case is no longer open.']);
        }
        if ($action === 'decline') {
            if ($currentStatus !== AppointmentReassignment::STATUS_PENDING_REVIEW) {
                throw ValidationException::withMessages(['case' => 'This case has already been declined.']);
            }
            return $this->changeStatus($reassignment, AppointmentReassignment::STATUS_DECLINED_BY_PATIENT, $userId, $remarks);
        }

        if ($action === 'cancel') {
            return DB::transaction(function () use ($reassignment, $userId, $remarks): AppointmentReassignment {
                $locked = AppointmentReassignment::query()->whereKey($reassignment->id)->lockForUpdate()->firstOrFail();
                if (! in_array($locked->status, [
                    AppointmentReassignment::STATUS_PENDING_REVIEW,
                    AppointmentReassignment::STATUS_DECLINED_BY_PATIENT,
                ], true)) {
                    throw ValidationException::withMessages(['case' => 'This reassignment case is no longer open.']);
                }

                $appointment = Appointment::query()->findOrFail($locked->appointment_id);
                $this->appointments->cancel($appointment, $userId, $remarks ?? 'Patient cancelled after provider unavailability.');
                return $this->setStatus($locked, AppointmentReassignment::STATUS_CANCELLED, $userId, null, null, $remarks);
            });
        }

        if ($action === 'waitlist') {
            $locked = $reassignment->fresh(['appointment']);
            $this->addCaseToWaitlist($locked, $locked->appointment, $userId, $remarks);

            return $locked->fresh(['appointment', 'waitlist']);
        }

        if (! in_array($action, ['accept', 'approve', 'pick', 'reschedule'], true)) {
            throw ValidationException::withMessages(['action' => 'Choose a valid reassignment action.']);
        }

        $case = $reassignment->fresh(['appointment', 'suggestedSlot']);
        if (! in_array($case->status, [
            AppointmentReassignment::STATUS_PENDING_REVIEW,
            AppointmentReassignment::STATUS_DECLINED_BY_PATIENT,
        ], true)) {
            throw ValidationException::withMessages(['case' => 'This reassignment case is no longer open.']);
        }
        $providerId ??= $case->suggested_provider_id;
        $slotId ??= $case->suggested_slot_id;
        if (! $providerId || ! $slotId) {
            throw ValidationException::withMessages(['appointment_slot_id' => 'Select an available doctor and slot.']);
        }
        $slot = AppointmentSlot::query()->findOrFail($slotId);
        if ((int) $slot->provider_id !== $providerId) {
            throw ValidationException::withMessages(['appointment_slot_id' => 'The selected slot does not belong to that doctor.']);
        }
        $case->loadMissing('appointment.appointmentType');
        if (($slot->appointment_type_id !== null && (int) $slot->appointment_type_id !== (int) $case->appointment->appointment_type_id)
            || $slot->starts_at->lt(now())
            || $slot->starts_at->gt($case->appointment->starts_at->copy()->addDays((int) config('appointments.reassignment_window_days', 7)))) {
            throw ValidationException::withMessages([
                'appointment_slot_id' => 'Choose an upcoming slot within the reassignment window that matches the consultation type.',
            ]);
        }

        $targetStatus = $action === 'reschedule'
            ? AppointmentReassignment::STATUS_RESCHEDULED
            : AppointmentReassignment::STATUS_APPROVED;
        $result = DB::transaction(function () use ($case, $providerId, $slotId, $slot, $userId, $remarks, $targetStatus): AppointmentReassignment {
            $lockedCase = AppointmentReassignment::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            if (! in_array($lockedCase->status, [
                AppointmentReassignment::STATUS_PENDING_REVIEW,
                AppointmentReassignment::STATUS_DECLINED_BY_PATIENT,
            ], true)) {
                throw ValidationException::withMessages(['case' => 'This reassignment case has already been decided.']);
            }
            $this->appointments->reassignProvider(
                $lockedCase->appointment,
                $providerId,
                $slotId,
                $slot->starts_at,
                $userId,
                $remarks ?? 'Provider reassigned due to unavailability.'
            );
            $updated = $this->setStatus($lockedCase, $targetStatus, $userId, $providerId, $slotId, $remarks);
            $this->audit->log('APPOINTMENT_REASSIGNMENT_DECIDED', 'appointment_reassignment', $updated->id, 'SUCCESS', [
                'status' => $targetStatus,
                'provider_id' => $providerId,
                'appointment_slot_id' => $slotId,
            ], $userId);

            return $updated;
        });
        $this->notifyParticipants($result->fresh(['appointment.patient.user', 'suggestedProvider.user']), $userId);

        return $result;
    }

    private function changeStatus(AppointmentReassignment $case, string $status, int $userId, ?string $remarks): AppointmentReassignment
    {
        $result = DB::transaction(function () use ($case, $status, $userId, $remarks): AppointmentReassignment {
            $locked = AppointmentReassignment::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== AppointmentReassignment::STATUS_PENDING_REVIEW) {
                throw ValidationException::withMessages(['case' => 'This reassignment case has already been decided.']);
            }

            return $this->setStatus($locked, $status, $userId, null, null, $remarks);
        });
        $this->audit->log('APPOINTMENT_REASSIGNMENT_DECIDED', 'appointment_reassignment', $result->id, 'SUCCESS', ['status' => $status], $userId);
        $this->notifyParticipants($result->fresh(['appointment.patient.user', 'suggestedProvider.user']), $userId);

        return $result;
    }

    private function setStatus(
        AppointmentReassignment $case,
        string $status,
        int $userId,
        ?int $providerId,
        ?int $slotId,
        ?string $remarks
    ): AppointmentReassignment {
        return DB::transaction(function () use ($case, $status, $userId, $providerId, $slotId, $remarks): AppointmentReassignment {
            $locked = AppointmentReassignment::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $locked->update([
                'status' => $status,
                'decided_by' => $userId,
                'decided_at' => now(),
                'suggested_provider_id' => $providerId ?? $locked->suggested_provider_id,
                'suggested_slot_id' => $slotId ?? $locked->suggested_slot_id,
                'suggested_starts_at' => $slotId ? AppointmentSlot::query()->find($slotId)?->starts_at : $locked->suggested_starts_at,
            ]);
            $this->recordHistory($locked, $from, $status, $userId, $providerId, $slotId, $slotId ? AppointmentSlot::query()->find($slotId)?->starts_at : null, $remarks);
            $this->audit->log('APPOINTMENT_REASSIGNMENT_STATUS_CHANGED', 'appointment_reassignment', $locked->id, 'SUCCESS', [
                'from_status' => $from,
                'to_status' => $status,
            ], $userId);

            return $locked->fresh();
        });
    }

    private function addCaseToWaitlist(
        AppointmentReassignment $case,
        Appointment $appointment,
        ?int $userId,
        ?string $remarks = null
    ): void {
        DB::transaction(function () use ($case, $appointment, $userId, $remarks): void {
            $locked = AppointmentReassignment::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === AppointmentReassignment::STATUS_WAITLISTED) {
                return;
            }
            if (! in_array($locked->status, [
                AppointmentReassignment::STATUS_PENDING_REVIEW,
                AppointmentReassignment::STATUS_DECLINED_BY_PATIENT,
            ], true)) {
                throw ValidationException::withMessages(['case' => 'This reassignment case is no longer open.']);
            }
            $waitlist = $this->appointments->addToWaitlist([
                'patient_id' => $appointment->patient_id,
                'department_id' => $appointment->department_id,
                'appointment_type_id' => $appointment->appointment_type_id,
                'preferred_date' => $appointment->starts_at->toDateString(),
                'status' => Waitlist::STATUS_WAITING,
            ]);
            $from = $locked->status;
            $nextAvailableDates = $this->nextAvailableDates($appointment);
            if (in_array($appointment->status, [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED], true)) {
                $this->appointments->cancel(
                    $appointment,
                    $userId,
                    'Appointment moved to the waitlist because the provider is unavailable.'
                );
            }
            $locked->update([
                'status' => AppointmentReassignment::STATUS_WAITLISTED,
                'waitlist_id' => $waitlist->id,
                'next_available_dates' => $nextAvailableDates,
                'decided_by' => $userId,
                'decided_at' => now(),
            ]);
            $this->recordHistory($locked, $from, AppointmentReassignment::STATUS_WAITLISTED, $userId, null, null, null, $remarks ?? 'No suitable provider slot was available.');
            $this->audit->log('APPOINTMENT_REASSIGNMENT_WAITLISTED', 'appointment_reassignment', $locked->id, 'SUCCESS', [
                'waitlist_id' => $waitlist->id,
                'next_available_dates' => $nextAvailableDates,
            ], $userId);
        });
    }

    private function nextAvailableDates(Appointment $appointment): array
    {
        $dates = AppointmentSlot::query()
            ->where('status', AppointmentSlot::STATUS_AVAILABLE)
            ->where('starts_at', '>', $appointment->starts_at->copy()->addDays((int) config('appointments.reassignment_window_days', 7)))
            ->where(function ($query) use ($appointment): void {
                $query->whereNull('appointment_type_id')
                    ->orWhere('appointment_type_id', $appointment->appointment_type_id);
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('provider_unavailabilities')
                    ->whereColumn('provider_unavailabilities.provider_id', 'appointment_slots.provider_id')
                    ->whereColumn('provider_unavailabilities.starts_at', '<', 'appointment_slots.ends_at')
                    ->whereColumn('provider_unavailabilities.ends_at', '>', 'appointment_slots.starts_at');
            })
            ->whereHas('provider', fn ($query) => $query->where('active', true)->where('department_id', $appointment->department_id))
            ->orderBy('starts_at')
            ->limit(3)
            ->pluck('starts_at')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->values()
            ->all();

        return $dates;
    }

    private function isCompatibleConsultation(?AppointmentType $appointmentType, AppointmentSlot $slot): bool
    {
        return $slot->appointment_type_id === null
            || ! $appointmentType
            || (int) $slot->appointment_type_id === (int) $appointmentType->id;
    }

    private function notifyParticipants(AppointmentReassignment $case, ?int $actorId): void
    {
        $case->loadMissing(['appointment.patient.user', 'suggestedProvider.user']);
        $appointment = $case->appointment;
        $data = [
            'reassignment_id' => $case->id,
            'appointment_id' => $appointment->id,
            'status' => $case->status,
            'suggested_doctor' => $case->suggestedProvider?->full_name,
            'new_time' => $case->suggested_starts_at?->toIso8601String(),
            'message' => $case->status === AppointmentReassignment::STATUS_WAITLISTED
                ? 'Your doctor is unavailable and we could not find another open slot yet. Your request has been waitlisted; staff will follow up.'
                : sprintf(
                    'Your doctor is unavailable. Suggested doctor: %s. Suggested time: %s.',
                    $case->suggestedProvider?->full_name ?? 'To be confirmed by staff',
                    $case->suggested_starts_at?->format('M j, Y g:i A') ?? 'To be confirmed'
                ),
            'url' => route('patient.reassignments.show', $case),
        ];

        $notification = new AppointmentReassignmentNotification($data);
        $patientUser = $appointment->patient?->user;
        if ($patientUser) {
            $patientUser->notify($notification);
        }

        $providerUser = Provider::query()->find($case->original_provider_id)?->user;
        if ($providerUser && $providerUser->id !== $patientUser?->id) {
            $providerUser->notify($notification);
        }

        $this->audit->log('APPOINTMENT_REASSIGNMENT_NOTIFIED', 'appointment_reassignment', $case->id, 'SUCCESS', [
            'patient_notified' => (bool) $patientUser,
            'requesting_doctor_notified' => (bool) $providerUser,
        ], $actorId);
    }

    private function recordHistory(
        AppointmentReassignment $case,
        ?string $from,
        string $to,
        ?int $userId,
        ?int $providerId,
        ?int $slotId,
        mixed $startsAt,
        ?string $remarks
    ): void {
        AppointmentReassignmentHistory::create([
            'appointment_reassignment_id' => $case->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $userId,
            'provider_id' => $providerId,
            'appointment_slot_id' => $slotId,
            'starts_at' => $startsAt,
            'remarks' => $remarks,
        ]);
    }
}
