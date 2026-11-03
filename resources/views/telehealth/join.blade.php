@extends('layouts.hims')

@section('title', 'Private Telehealth Consultation')

@section('content')
<main class="mx-auto max-w-6xl space-y-5 px-1 sm:px-2">
    @if ($demoMode)
        <div role="alert" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm font-semibold text-amber-950">
            Demo video mode: not suitable for real patient consultations.
        </div>
    @endif

    <header class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between sm:p-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Private virtual consultation</p>
            <h1 class="mt-1 text-xl font-bold text-slate-900 sm:text-2xl">Telehealth consultation</h1>
            <p class="mt-2 text-sm text-slate-600">Appointment time: {{ $session->appointment->starts_at->format('M j, Y g:i A') }} (Philippine time)</p>
        </div>
        <span class="inline-flex w-fit items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700">
            <span class="h-2 w-2 rounded-full bg-teal-600"></span>
            {{ $role === 'doctor' ? 'Assigned doctor' : 'Your appointment' }}
        </span>
    </header>

    @if (! $joined)
        <section class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm leading-6 text-sky-950 sm:p-6">
            <h2 class="font-bold">Privacy before you join</h2>
            <ul class="mt-2 list-inside list-disc space-y-1">
                <li>Your consultation is confidential. Join from a private place where others cannot overhear.</li>
                <li>Recording is not allowed unless both the patient and doctor consent.</li>
                <li>This platform does not store the video.</li>
            </ul>
        </section>

        <section class="grid gap-5 lg:grid-cols-[1.2fr_0.8fr]">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold text-slate-900">Before connecting</h2>
                <ul class="mt-3 space-y-2 text-sm text-slate-700">
                    <li>✓ Check that your camera is available.</li>
                    <li>✓ Check that your microphone is available.</li>
                    <li>✓ Use a stable, private internet connection.</li>
                    <li>✓ Use headphones if other people are nearby.</li>
                </ul>
                @if ($errors->any())
                    <div role="alert" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                    </div>
                @endif
                <form method="POST" action="{{ route('telehealth.connect', $session) }}" class="mt-5 border-t border-slate-200 pt-4">
                    @csrf
                    <input type="hidden" name="join_token" value="{{ $joinToken }}">
                    <label class="flex cursor-pointer items-start gap-3 text-sm leading-6 text-slate-700">
                        <input type="checkbox" name="privacy_acknowledged" value="1" required class="mt-1 rounded border-slate-300 text-teal-700 focus:ring-teal-600">
                        <span>I have read and acknowledge this privacy notice for this consultation.</span>
                    </label>
                    <button type="submit" class="mt-4 w-full rounded-xl bg-teal-700 px-5 py-3 text-sm font-semibold text-white hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 sm:w-auto">
                        Acknowledge and join
                    </button>
                </form>
            </div>
            <aside class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold text-slate-900">Confidentiality checklist</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">Only the patient on this appointment and the currently assigned doctor may join. Current telehealth consent is checked again when you connect.</p>
                <p class="mt-4 text-xs leading-5 text-slate-500">If you are in a shared area, move somewhere private before continuing. For technical help, contact the hospital.</p>
            </aside>
        </section>
    @else
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if ($videoDetails['provider'] === 'jitsi_demo')
                <div class="border-b border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    Demo connection only. Do not discuss real patient information in this demo room.
                </div>
                <div id="jitsi-demo-frame" class="min-h-[55vh] bg-slate-950 sm:min-h-[70vh]" aria-label="Demo video room"></div>
            @else
                <div class="space-y-4 p-4 sm:p-6">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <h2 class="font-semibold text-slate-900">Your private Zoom consultation is ready</h2>
                        <p class="mt-1 text-sm text-slate-600">Zoom waiting room is enabled. The doctor must admit you.</p>
                        @if ($videoDetails['passcode'])
                            <p class="mt-3 text-sm text-slate-700">Meeting passcode: <strong class="select-all font-mono">{{ $videoDetails['passcode'] }}</strong></p>
                        @endif
                    </div>
                    <a href="{{ $videoDetails['join_url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex w-full items-center justify-center rounded-xl bg-teal-700 px-5 py-3 text-sm font-semibold text-white hover:bg-teal-800 sm:w-auto">
                        Join consultation in Zoom
                    </a>
                </div>
            @endif
        </section>
        <div class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
            <p class="text-sm text-slate-600">Keep this page open so your private session heartbeat continues.</p>
            <form method="POST" action="{{ route('telehealth.leave', $session) }}">
                @csrf
                @if ($role === 'doctor')
                    <input type="hidden" name="end_session" value="1">
                    <button class="w-full rounded-xl bg-rose-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-800 sm:w-auto">End consultation</button>
                @else
                    <button class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:w-auto">Leave consultation</button>
                @endif
            </form>
        </div>
    @endif
</main>
@endsection

@push('scripts')
@if ($joined && $videoDetails['provider'] === 'jitsi_demo')
<script src="https://meet.jit.si/external_api.js"></script>
<script>
    (() => {
        const container = document.getElementById('jitsi-demo-frame');
        if (!container || typeof window.JitsiMeetExternalAPI !== 'function') return;
        new window.JitsiMeetExternalAPI('meet.jit.si', {
            roomName: @json($videoDetails['room_name']),
            parentNode: container,
            width: '100%',
            height: Math.max(420, Math.min(window.innerHeight * 0.7, 760)),
            userInfo: { displayName: @json($role === 'doctor' ? 'Assigned doctor (demo)' : 'Patient (demo)') },
            configOverwrite: { prejoinPageEnabled: false, disableDeepLinking: true },
            interfaceConfigOverwrite: { TOOLBAR_BUTTONS: ['microphone', 'camera', 'hangup', 'chat', 'settings'] }
        });
    })();
</script>
@endif
@if ($joined)
<script>
    (() => {
        const heartbeatUrl = @json(route('session.heartbeat'));
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const sendHeartbeat = () => fetch(heartbeatUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).then((response) => {
            if (!response.ok) window.location.assign(@json(route('login')) + '?timeout=1');
        }).catch((error) => console.error('Telehealth session heartbeat failed.', error));
        sendHeartbeat();
        window.setInterval(sendHeartbeat, 45000);
    })();
</script>
@endif
@endpush
