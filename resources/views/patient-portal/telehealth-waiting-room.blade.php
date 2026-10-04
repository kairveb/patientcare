@extends('layouts.hims')

@section('title', 'Virtual Waiting Room')
@section('page-title', 'Virtual Waiting Room')
@section('page-kicker', 'Patient portal')
@section('page-description', 'Check your devices and wait for your doctor to start the consultation.')

@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-xl font-semibold text-slate-900">{{ $session->appointment?->provider?->full_name ?? 'Your doctor' }}</h2>
        <p class="mt-1 text-sm text-slate-600">{{ $session->appointment?->department?->name ?? 'Telehealth consultation' }}</p>
        <p class="mt-1 text-sm text-slate-600">{{ $session->start_time?->format('M d, Y g:i A') }}</p>

        <div class="mt-5 rounded-xl border border-teal-200 bg-teal-50 p-4">
            <p class="text-sm font-medium text-teal-950">
                @if ($queuePosition !== null)
                    Queue position: {{ $queuePosition }} of {{ $queueTotal }} for this doctor today
                @else
                    Your queue position will be available on the appointment day.
                @endif
            </p>
            <p class="mt-1 text-sm text-teal-900">
                {{ $doctorStarted ? 'Your doctor has started the session.' : 'Your doctor has not started the session yet.' }}
            </p>
        </div>

        @unless ($hasTelehealthConsent)
            <div role="alert" class="mt-5 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                Current telehealth consent is required before joining.
                <a href="{{ route('patient-consents.portal', ['required' => 'TELEHEALTH']) }}" class="font-semibold underline">Review and record consent</a>
            </div>
        @endunless
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="font-semibold text-slate-900">Camera and microphone check</h2>
        <p class="mt-1 text-sm text-slate-600">Allow access to check your devices. The test does not record or transmit audio or video, and releases the devices immediately.</p>
        <button id="device-check" type="button" class="mt-4 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">Test camera and microphone</button>
        <p id="device-check-result" class="mt-3 text-sm text-slate-700" role="status" aria-live="polite"></p>
    </section>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('patients.portal.telehealth') }}" class="text-sm font-medium text-slate-600 hover:underline">Back to telehealth appointments</a>
        @if ($canJoin)
            <a href="{{ $joinUrl }}" class="rounded-lg bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-800">Join virtual consultation</a>
        @else
            <button type="button" disabled class="cursor-not-allowed rounded-lg bg-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-600">Join virtual consultation</button>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.getElementById('device-check')?.addEventListener('click', async () => {
        const result = document.getElementById('device-check-result');

        if (!navigator.mediaDevices?.getUserMedia) {
            result.textContent = 'Camera and microphone access is not supported by this browser.';
            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: true });
            stream.getTracks().forEach((track) => track.stop());
            result.textContent = 'Camera and microphone are available. No media was recorded or transmitted.';
        } catch {
            result.textContent = 'Device check could not access the camera and microphone. Check browser permissions or device connections.';
        }
    });
</script>
@endpush
