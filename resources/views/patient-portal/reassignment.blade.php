@extends('layouts.hims')
@section('title', 'Review appointment update')
@section('content')
@php($open = in_array($reassignment->status, ['PENDING_REVIEW', 'DECLINED_BY_PATIENT'], true))
<div class="mx-auto max-w-3xl space-y-5">
    <a href="{{ route('patient.reassignments.index') }}" class="text-sm font-semibold text-teal-700">← All appointment updates</a>
    <section class="rounded-xl border border-amber-200 bg-amber-50 p-5">
        <h1 class="text-xl font-bold text-slate-900">Your doctor is unavailable</h1>
        <p class="mt-2 text-sm text-slate-700">Your original appointment was with {{ $reassignment->originalProvider->full_name }} at {{ $reassignment->appointment->starts_at->format('M j, Y g:i A') }}. Staff will keep this case open until a safe option is confirmed.</p>
        <p class="mt-2 text-sm">Current status: <strong>{{ str_replace('_', ' ', $reassignment->status) }}</strong></p>
    </section>

    @if (session('success'))<div role="status" class="rounded border border-green-200 bg-green-50 p-3 text-sm text-green-800">{{ session('success') }}</div>@endif
    @if ($errors->any())<div role="alert" class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif

    @if ($reassignment->status === 'WAITLISTED')
        <p class="rounded-xl border bg-white p-5 text-sm text-slate-700">You are on the hospital waitlist. Registration staff will contact you when a suitable appointment becomes available.</p>
    @elseif ($open)
        <section class="space-y-3 rounded-xl border bg-white p-5">
            <h2 class="font-semibold text-slate-900">Available doctor and appointment options</h2>
            @forelse ($candidates as $candidate)
                <article class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3">
                    <div><p class="font-medium text-slate-900">{{ $candidate['provider']->full_name }}</p><p class="text-sm text-slate-600">{{ $candidate['slot']->starts_at->format('M j, Y g:i A') }} · {{ $candidate['explanation'] }}</p></div>
                    <form method="POST" action="{{ route('patient.reassignments.respond', $reassignment) }}">@csrf
                        <input type="hidden" name="action" value="{{ $candidate['provider']->id === $reassignment->suggested_provider_id && $candidate['slot']->id === $reassignment->suggested_slot_id ? 'accept' : 'pick' }}">
                        <input type="hidden" name="provider_id" value="{{ $candidate['provider']->id }}">
                        <input type="hidden" name="appointment_slot_id" value="{{ $candidate['slot']->id }}">
                        <button class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white">{{ $candidate['provider']->id === $reassignment->suggested_provider_id && $candidate['slot']->id === $reassignment->suggested_slot_id ? 'Accept suggestion' : 'Choose this time' }}</button>
                    </form>
                </article>
            @empty
                <p class="text-sm text-slate-600">No suitable open slot has been found yet. The case remains open for registration staff.</p>
            @endforelse
            <div class="flex flex-wrap gap-3 border-t pt-4">
                <form method="POST" action="{{ route('patient.reassignments.respond', $reassignment) }}">@csrf<input type="hidden" name="action" value="decline"><button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Decline suggestion</button></form>
                <form method="POST" action="{{ route('patient.reassignments.respond', $reassignment) }}">@csrf<input type="hidden" name="action" value="cancel"><button class="rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-700">Cancel appointment</button></form>
            </div>
        </section>
    @endif

    <section class="rounded-xl border bg-white p-5">
        <h2 class="font-semibold text-slate-900">Update history</h2>
        <ol class="mt-3 space-y-2 text-sm text-slate-600">
            @foreach ($reassignment->histories as $history)
                <li>{{ str_replace('_', ' ', $history->to_status) }} · {{ $history->created_at->format('M j, Y g:i A') }} @if($history->remarks) — {{ $history->remarks }} @endif</li>
            @endforeach
        </ol>
    </section>
</div>
@endsection
