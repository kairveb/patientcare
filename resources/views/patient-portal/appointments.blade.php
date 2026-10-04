@extends('layouts.hims')

@section('title', 'My Appointments')
@section('page-title', 'My Appointments')
@section('page-kicker', 'Patient portal')
@section('page-description', 'Track scheduled visits and appointment history securely.')

@section('content')
<div class="space-y-4">
    <div class="flex justify-end">
        <a href="{{ route('appointments.create') }}" class="rounded-xl bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">Book appointment</a>
    </div>
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
                        @if ($appointment->starts_at?->isFuture() && ! in_array($appointment->status, [\App\Models\Appointment::STATUS_CANCELLED, \App\Models\Appointment::STATUS_COMPLETED], true))
                            <div class="mt-3 space-y-2">
                                <form method="POST" action="{{ route('appointments.reschedule', $appointment) }}" class="flex flex-wrap justify-end gap-2">
                                    @csrf
                                    <input type="datetime-local" name="starts_at" required class="rounded-lg border border-slate-300 px-2 py-1 text-xs">
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
