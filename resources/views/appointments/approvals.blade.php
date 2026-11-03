@extends('layouts.hims')
@section('title', 'Appointment Approval Queue')
@section('page-title', 'Appointment approvals')
@section('page-description', 'Review patient-submitted appointment requests.')
@section('content')
<div class="space-y-4">
    @forelse ($appointments as $appointment)
        <article class="rounded-xl border bg-white p-5 shadow-sm">
            <div class="flex flex-col justify-between gap-4 md:flex-row md:items-start">
                <div>
                    <h2 class="font-semibold">{{ $appointment->patient?->full_name }} · {{ $appointment->provider?->full_name }}</h2>
                    <p class="text-sm text-slate-600">{{ $appointment->appointmentType?->name }} · {{ $appointment->starts_at->format('M j, Y g:i A') }}</p>
                    <p class="mt-1 text-sm text-slate-600">Booking source: {{ str_replace('_', ' ', $appointment->booking_source) }}</p>
                    @if ($appointment->reason)<p class="mt-2 text-sm">{{ $appointment->reason }}</p>@endif
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach (['confirm' => 'Confirm', 'reject' => 'Reject'] as $decision => $label)
                        <form method="POST" action="{{ route('appointments.decide', $appointment) }}" class="flex flex-wrap gap-2">
                            @csrf
                            <input type="hidden" name="decision" value="{{ $decision }}">
                            <input name="reason" required minlength="3" maxlength="1000" placeholder="{{ $decision === 'reject' ? 'Required reason' : 'Required note' }}" class="w-48 rounded-lg border-slate-300 text-sm">
                            <button class="rounded-lg px-3 py-2 text-sm font-semibold {{ $decision === 'confirm' ? 'bg-teal-700 text-white' : 'border border-red-300 text-red-800' }}">{{ $label }}</button>
                        </form>
                    @endforeach
                </div>
            </div>
        </article>
    @empty
        <div class="rounded-xl border border-dashed bg-white p-8 text-center text-sm text-slate-600">No appointment requests are waiting for approval.</div>
    @endforelse
    {{ $appointments->links() }}
</div>
@endsection
