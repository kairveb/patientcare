<?php

namespace App\Services;

use App\Models\AppointmentSlot;
use App\Models\Provider;
use App\Models\ProviderSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SchedulingService
{
    /**
     * Generate appointment slots for a provider schedule for a given date range.
     */
    public function generateSlots(ProviderSchedule $schedule, string $fromDate, string $toDate): int
    {
        return DB::transaction(function () use ($schedule, $fromDate, $toDate): int {
            Provider::query()->whereKey($schedule->provider_id)->lockForUpdate()->firstOrFail();
            $count = 0;
            $start = Carbon::parse($fromDate);
            $end = Carbon::parse($toDate);

            for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                if (! $this->shouldGenerateForDate($schedule, $date)) {
                    continue;
                }

                $slotStart = $date->copy()->setTimeFromTimeString($schedule->start_time);
                $slotEnd = $date->copy()->setTimeFromTimeString($schedule->end_time);
                $duration = $schedule->slot_duration ?: 30;

                while ($slotStart->copy()->addMinutes($duration)->lte($slotEnd)) {
                    $blockStart = $slotStart->copy();
                    $blockEnd = $blockStart->copy()->addMinutes($duration);
                    $slotStart = $blockEnd->copy();

                    if ($this->isInBreakWindow($schedule, $date, $blockStart, $blockEnd)
                        || $this->providerIsUnavailable((int) $schedule->provider_id, $blockStart, $blockEnd)) {
                        continue;
                    }

                    $slot = AppointmentSlot::firstOrCreate(
                        ['provider_id' => $schedule->provider_id, 'starts_at' => $blockStart],
                        ['ends_at' => $blockEnd, 'status' => AppointmentSlot::STATUS_AVAILABLE]
                    );
                    $count += (int) $slot->wasRecentlyCreated;
                }
            }

            return $count;
        });
    }

    protected function shouldGenerateForDate(ProviderSchedule $schedule, Carbon $date): bool
    {
        if ($date->dayOfWeek !== $schedule->day_of_week) {
            return false;
        }

        return !($schedule->unavailable_date && $schedule->unavailable_date->isSameDay($date));
    }

    protected function isInBreakWindow(ProviderSchedule $schedule, Carbon $date, Carbon $blockStart, Carbon $blockEnd): bool
    {
        if (!$schedule->break_start || !$schedule->break_end) {
            return false;
        }

        $breakStart = $date->copy()->setTimeFromTimeString($schedule->break_start);
        $breakEnd = $date->copy()->setTimeFromTimeString($schedule->break_end);

        return $blockStart->lt($breakEnd) && $blockEnd->gt($breakStart);
    }

    /**
     * Get available slots for a provider on a given date (or date range).
     */
    public function availableSlots(int $providerId, string $date, ?int $appointmentTypeId = null)
    {
        $normalizedDate = $this->normalizeDate($date);
        $slots = $this->buildAvailableSlotsQuery($providerId, $normalizedDate, $appointmentTypeId)->get();

        if ($slots->isNotEmpty()) {
            return $slots;
        }

        $this->generateMissingSlotsForDate($providerId, $normalizedDate);

        return $this->buildAvailableSlotsQuery($providerId, $normalizedDate, $appointmentTypeId)->get();
    }

    protected function normalizeDate(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
            return Carbon::createFromFormat('d/m/Y', $date)->format('Y-m-d');
        }

        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $date)) {
            return Carbon::createFromFormat('d-m-Y', $date)->format('Y-m-d');
        }

        return Carbon::parse($date)->format('Y-m-d');
    }

    protected function generateMissingSlotsForDate(int $providerId, string $date): void
    {
        $targetDate = Carbon::parse($date);

        $schedules = ProviderSchedule::where('provider_id', $providerId)->get();
        foreach ($schedules as $schedule) {
            if (!$this->shouldGenerateForDate($schedule, $targetDate)) {
                continue;
            }

            $this->generateSlots($schedule, $targetDate->copy()->format('Y-m-d'), $targetDate->copy()->format('Y-m-d'));
        }
    }

    protected function buildAvailableSlotsQuery(int $providerId, string $date, ?int $appointmentTypeId = null)
    {
        return AppointmentSlot::where('provider_id', $providerId)
            ->whereDate('starts_at', $date)
            ->where('status', AppointmentSlot::STATUS_AVAILABLE)
            ->whereNotExists(function ($query) use ($providerId): void {
                $query->selectRaw('1')
                    ->from('provider_unavailabilities')
                    ->where('provider_unavailabilities.provider_id', $providerId)
                    ->whereColumn('provider_unavailabilities.starts_at', '<', 'appointment_slots.ends_at')
                    ->whereColumn('provider_unavailabilities.ends_at', '>', 'appointment_slots.starts_at');
            })
            ->when($appointmentTypeId, fn ($q) => $q->where(function ($typeQuery) use ($appointmentTypeId): void {
                $typeQuery->whereNull('appointment_type_id')
                    ->orWhere('appointment_type_id', $appointmentTypeId);
            }))
            ->orderBy('starts_at');
    }

    /**
     * Check if a provider has a conflicting appointment in the given window.
     */
    public function providerHasConflict(int $providerId, Carbon $startsAt, Carbon $endsAt, ?int $ignoreAppointmentId = null): bool
    {
        $query = \App\Models\Appointment::where('provider_id', $providerId)
            ->whereIn('status', ['PENDING', 'CONFIRMED', 'CHECKED-IN', 'IN-CONSULTATION'])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);

        if ($ignoreAppointmentId) {
            $query->where('id', '!=', $ignoreAppointmentId);
        }

        return $query->exists();
    }

    /**
     * Serialize appointment changes for a provider within the surrounding transaction.
     */
    public function lockProvider(int $providerId): Provider
    {
        return Provider::query()->whereKey($providerId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Lock and reserve the exact available slot selected by the client.
     */
    public function reserveSlot(int $slotId, int $providerId, Carbon $startsAt, ?int $duration = null): AppointmentSlot
    {
        Provider::query()->whereKey($providerId)->lockForUpdate()->firstOrFail();
        $slot = AppointmentSlot::query()->whereKey($slotId)->lockForUpdate()->first();

        if (! $slot
            || (int) $slot->provider_id !== $providerId
            || ! $slot->starts_at->equalTo($startsAt)
            || $slot->ends_at->lessThanOrEqualTo($slot->starts_at)
            || $slot->status !== AppointmentSlot::STATUS_AVAILABLE) {
            throw ValidationException::withMessages([
                'appointment_slot_id' => 'The selected appointment slot is unavailable or does not match the selected provider and time.',
            ]);
        }
        if ($this->providerIsUnavailable($providerId, $slot->starts_at, $slot->ends_at)) {
            $slot->update(['status' => AppointmentSlot::STATUS_BLOCKED]);
            throw ValidationException::withMessages([
                'appointment_slot_id' => 'The selected provider is unavailable during this time.',
            ]);
        }

        $slotDuration = (int) $slot->starts_at->diffInMinutes($slot->ends_at);
        if ($slotDuration < 5 || $slotDuration > 240) {
            throw ValidationException::withMessages([
                'appointment_slot_id' => 'The selected appointment slot has an invalid duration.',
            ]);
        }

        if ($duration !== null && $duration !== $slotDuration) {
            throw ValidationException::withMessages([
                'duration' => 'The selected duration does not match the appointment slot.',
            ]);
        }

        $slot->update(['status' => AppointmentSlot::STATUS_BOOKED]);

        return $slot;
    }

    public function providerIsUnavailable(int $providerId, Carbon $startsAt, Carbon $endsAt): bool
    {
        return DB::table('provider_unavailabilities')
            ->where('provider_id', $providerId)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }
}
