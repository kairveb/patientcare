<?php

namespace App\Services;

use App\Models\AppointmentSlot;
use App\Models\Provider;
use App\Models\Waitlist;
use App\Notifications\AppointmentJourneyNotification;

class AppointmentWaitlistService
{
    public function offerMatchingPatients(AppointmentSlot $slot): int
    {
        $provider = Provider::with(['department', 'specialties'])->find($slot->provider_id);
        if (! $provider) {
            return 0;
        }

        $waitlists = Waitlist::query()
            ->where('status', Waitlist::STATUS_WAITING)
            ->where(function ($query) use ($provider): void {
                $query->where('provider_id', $provider->id)
                    ->orWhere(function ($departmentQuery) use ($provider): void {
                        $departmentQuery->whereNull('provider_id')
                            ->where('department_id', $provider->department_id);
                    });
            })
            ->where(function ($query) use ($slot): void {
                $query->whereNull('appointment_type_id')
                    ->orWhere('appointment_type_id', $slot->appointment_type_id);
            })
            ->where(function ($query) use ($slot): void {
                $query->whereNull('preferred_date')
                    ->orWhereDate('preferred_date', $slot->starts_at->toDateString());
            })
            ->with('patient.user')
            ->orderBy('created_at')
            ->get();

        $offered = 0;
        foreach ($waitlists as $waitlist) {
            $waitlist->update(['status' => Waitlist::STATUS_OFFERED]);
            $patientUser = $waitlist->patient?->user;
            if ($patientUser) {
                $patientUser->notify(new AppointmentJourneyNotification(
                    'An appointment slot opened',
                    'A matching appointment slot is available for '.$slot->starts_at->format('M j, Y g:i A').'. Book online using the link below.',
                    null,
                    route('doctors.show', $provider),
                ));
            }
            $offered++;
        }

        return $offered;
    }
}
