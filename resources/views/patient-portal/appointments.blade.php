@extends('layouts.hims')

@section('title', 'My Appointments')
@section('page-title', 'My Appointments')
@section('page-kicker', 'Patient portal')
@section('page-description', 'Track scheduled visits and appointment history securely.')

@section('content')
<div class="space-y-4">
    <div class="flex justify-end">
        <a href="{{ route('doctors.index') }}" class="rounded-xl bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">Find a doctor</a>
    </div>
    @if ($followUps->isNotEmpty())
        <section class="card rounded-2xl border border-blue-200 bg-blue-50 p-5">
            <h2 class="font-semibold text-blue-950">Recommended follow-ups</h2>
            <div class="mt-3 space-y-3">
                @foreach ($followUps as $followUp)
                    <div class="flex flex-col justify-between gap-3 rounded-xl border border-blue-100 bg-white p-4 sm:flex-row sm:items-center">
                        <div>
                            <p class="font-medium">{{ $followUp->provider?->full_name ?? 'Your doctor' }}</p>
                            <p class="text-sm text-slate-600">Suggested date: {{ $followUp->follow_up_date->format('M j, Y') }}</p>
                        </div>
                        <a class="rounded-lg bg-blue-700 px-4 py-2 text-center text-sm font-semibold text-white" href="{{ route('doctors.show', ['provider' => $followUp->provider_id, 'follow_up_date' => $followUp->follow_up_date->toDateString(), 'parent_appointment_id' => $followUp->appointment_id, 'reason' => 'Recommended follow-up']) }}">Book follow-up</a>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
    <div class="card rounded-2xl border bg-white p-6 shadow-sm">
    @if ($appointments->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-500">No appointments scheduled yet.</div>
    @else
        <div class="space-y-3">
            @foreach ($appointments as $appointment)
                <div class="flex items-center justify-between rounded-xl border border-slate-200 p-4">
                    <div>
                        <p class="font-medium text-slate-900">{{ $appointment->appointmentType?->name ?? 'Consultation' }}</p>
                        <p class="text-sm text-slate-600">{{ $appointment->provider?->user?->name ?? 'Provider' }}</p>
                    </div>
                    <div class="text-right text-sm text-slate-600">
                        <p>{{ $appointment->starts_at?->format('M d, Y') }}</p>
                        <p>{{ $appointment->starts_at?->format('g:i A') }}</p>
                        <p class="mt-1 font-medium text-slate-900">{{ $appointment->status }}</p>
                        <p class="text-xs text-slate-500">Source: {{ str_replace('_', ' ', $appointment->booking_source) }}</p>
                        @if ($appointment->parent_appointment_id)
                            <p class="text-xs text-blue-700">Follow-up to {{ $appointment->parentAppointment?->appointment_number ?? 'appointment' }}</p>
                        @endif
                        @if ($appointment->starts_at?->isFuture() && ! in_array($appointment->status, [\App\Models\Appointment::STATUS_CANCELLED, \App\Models\Appointment::STATUS_COMPLETED], true))
                            <div class="mt-3 space-y-2">
                                <form method="POST" action="{{ route('appointments.reschedule', $appointment) }}" class="reschedule-form flex flex-wrap justify-end gap-2" data-appointment-slot-picker data-provider-id="{{ $appointment->provider_id }}" data-slots-url="{{ route('appointments.slots') }}">
                                    @csrf
                                    <label class="sr-only" for="reschedule-date-{{ $appointment->id }}">New appointment date</label>
                                    <input id="reschedule-date-{{ $appointment->id }}" type="date" data-slot-date required class="reschedule-date rounded-lg border border-slate-300 px-2 py-1 text-xs">
                                    <div class="reschedule-slots w-full text-xs text-slate-600" data-slot-results aria-live="polite"></div>
                                    <input type="hidden" name="starts_at" value="">
                                    <button type="submit" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700">Reschedule</button>
                                </form>
                                <form method="POST" action="{{ route('appointments.cancel', $appointment) }}">
                                    @csrf
                                    <button type="submit" class="rounded-lg border border-red-200 px-2 py-1 text-xs font-medium text-red-700">Cancel appointment</button>
                                </form>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $appointments->links() }}
        </div>
    @endif
    </div>
</div>
@endsection
