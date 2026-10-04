@extends('layouts.hims')

@section('title', 'Telehealth Session')

@section('content')
<div class="max-w-3xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-800">Telehealth Session</h1>
        <p class="text-sm text-slate-500 mt-1">Appointment {{ $session->appointment->appointment_number ?? '—' }}</p>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
            <div><span class="text-slate-500">Patient:</span> <span class="font-medium">{{ $session->appointment?->patient?->full_name ?? '—' }}</span></div>
            <div><span class="text-slate-500">Provider:</span> <span class="font-medium">{{ $session->appointment?->provider?->full_name ?? '—' }}</span></div>
            <div><span class="text-slate-500">Start:</span> {{ $session->start_time?->format('M d, Y g:i A') }}</div>
            <div><span class="text-slate-500">Duration:</span> {{ $session->duration }} minutes</div>
            <div><span class="text-slate-500">Status:</span> <span class="px-2 py-1 text-xs rounded-full bg-slate-100">{{ $session->status }}</span></div>
        </div>
    </div>

    @if (($isSessionDoctor || auth()->user()->hasRole('nurse')) && $session->intake)
        <section class="rounded-xl border border-slate-200 bg-white p-6">
            <h2 class="font-semibold text-slate-800">Patient pre-consultation intake</h2>
            @if ($session->intake->is_emergency)
                <div role="alert" class="mt-3 rounded-lg border border-red-300 bg-red-50 p-4 text-sm font-semibold text-red-900">
                    AI intake indicates emergency urgency. Review the patient promptly and use clinical judgment. This is decision support only.
                </div>
            @endif
            <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2">
                <div><dt class="font-medium text-slate-500">Symptoms</dt><dd>{{ $session->intake->symptoms }}</dd></div>
                <div><dt class="font-medium text-slate-500">Duration</dt><dd>{{ $session->intake->duration }}</dd></div>
                <div><dt class="font-medium text-slate-500">Severity</dt><dd>{{ $session->intake->severity }}/10</dd></div>
                <div><dt class="font-medium text-slate-500">AI urgency</dt><dd>{{ $session->intake->ai_urgency }} · decision support only</dd></div>
                <div><dt class="font-medium text-slate-500">Allergies</dt><dd>{{ $session->intake->allergies ?: 'None reported' }}</dd></div>
                <div><dt class="font-medium text-slate-500">Current medications</dt><dd>{{ $session->intake->current_medications ?: 'None reported' }}</dd></div>
                <div class="md:col-span-2"><dt class="font-medium text-slate-500">AI reasons</dt><dd>{{ implode(' ', $session->intake->ai_reasons ?? []) }}</dd></div>
            </dl>
        </section>
    @endif

    @if ($isSessionDoctor && $session->isLive())
        <div class="rounded-xl border border-red-200 bg-red-50 p-6">
            <h3 class="font-semibold text-red-900">Emergency escalation</h3>
            <p class="mt-1 text-sm text-red-800">Create an ER arrival record and alert the emergency team if the patient needs immediate in-person care.</p>
            <form method="POST" action="{{ route('telehealth.escalate-to-er', $session) }}" class="mt-4" onsubmit="return confirm('Escalate this patient to the Emergency Department now?')">
                @csrf
                <button type="submit" class="rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">Escalate to ER</button>
            </form>
        </div>
    @elseif ($session->status === \App\Models\TelehealthSession::STATUS_ESCALATED)
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-900">This session was escalated to the Emergency Department at {{ $session->escalated_at?->format('M d, Y g:i A') }}.</div>
    @endif

    @if ($isSessionDoctor)
        <section class="rounded-xl border border-indigo-200 bg-indigo-50 p-6">
            <h2 class="font-semibold text-indigo-950">Refer to face-to-face OPD visit</h2>
            <p class="mt-1 text-sm text-indigo-900">Create a follow-up request for this patient in the session's clinic department.</p>
            @if ($session->referrals->isNotEmpty())
                <div class="mt-4 space-y-3">
                    @foreach ($session->referrals as $referral)
                        <div class="rounded-lg border border-indigo-200 bg-white p-3 text-sm">
                            <p class="font-semibold text-slate-900">{{ $referral->department?->name ?? 'Outpatient Department' }} · {{ $referral->status }}</p>
                            <p class="mt-1 text-slate-700">{{ $referral->reason }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
            <form method="POST" action="{{ route('telehealth.face-to-face-referral', $session) }}" class="mt-4 space-y-3">
                @csrf
                <div>
                    <label for="face_to_face_reason" class="mb-1 block text-sm font-medium text-indigo-950">Referral reason</label>
                    <textarea id="face_to_face_reason" name="reason" rows="3" minlength="10" maxlength="2000" required class="w-full rounded-lg border border-indigo-200 px-3 py-2 text-sm">{{ old('reason') }}</textarea>
                    @error('reason')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="rounded-lg bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">Create OPD referral request</button>
            </form>
        </section>
    @endif

    @if ($canViewMeetingUrls)
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-800 mb-3">Meeting Information</h3>
            @if ($session->zoom_meeting_id)
                <p class="mb-2 text-sm">Meeting ID: <span class="font-mono">{{ $session->zoom_meeting_id }}</span></p>
            @endif
            @if ($session->join_url)
                <p class="text-sm">Join URL: <a href="{{ $session->join_url }}" target="_blank" rel="noopener noreferrer" class="text-teal-600 hover:underline">{{ $session->join_url }}</a></p>
            @endif
            @if ($session->host_start_url)
                <p class="mt-2 text-sm">Host start URL: <a href="{{ $session->host_start_url }}" target="_blank" rel="noopener noreferrer" class="text-teal-600 hover:underline">{{ $session->host_start_url }}</a></p>
            @endif
            @if (! $session->join_url)
                <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-700">
                    A protected local telehealth room is available; Zoom is not configured.
                </div>
            @endif
        </div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <h3 class="font-semibold text-slate-800 mb-3">Create / Update Meeting</h3>
        <form method="POST" action="{{ route('telehealth.create-meeting', $session) }}" class="flex gap-3 items-end">
            @csrf
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Start Date/Time</label>
                <input type="datetime-local" name="start_time" value="{{ $session->start_time?->format('Y-m-d\TH:i') }}" class="px-3 py-2 text-sm border border-slate-300 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Duration (min)</label>
                <input type="number" name="duration" value="{{ $session->duration }}" class="px-3 py-2 text-sm border border-slate-300 rounded-lg w-28">
            </div>
            <button type="submit" class="px-4 py-2 text-sm bg-teal-600 text-white rounded-lg">Create/Update Meeting</button>
        </form>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <div class="flex items-center justify-between gap-3 mb-4">
            <h3 class="font-semibold text-slate-800">Closeout consultation</h3>
            <span class="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-indigo-700">Clinical summary</span>
        </div>

        <form id="closeoutForm" class="space-y-4">
            @csrf
            <div class="grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label for="assessment" class="mb-1 block text-sm font-medium text-slate-700">Assessment</label>
                    <textarea id="assessment" name="assessment" rows="4" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Document the patient assessment and findings."></textarea>
                </div>
                <div class="md:col-span-2">
                    <label for="plan" class="mb-1 block text-sm font-medium text-slate-700">Plan</label>
                    <textarea id="plan" name="plan" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Outline treatment plan and recommended follow-up."></textarea>
                </div>
                <div class="md:col-span-2">
                    <label for="discharge_instructions" class="mb-1 block text-sm font-medium text-slate-700">Discharge instructions</label>
                    <textarea id="discharge_instructions" name="discharge_instructions" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Any care instructions or self-management advice."></textarea>
                </div>
                <div class="md:col-span-2">
                    <label for="clinic_note" class="mb-1 block text-sm font-medium text-slate-700">Clinic note</label>
                    <textarea id="clinic_note" name="clinic_note" rows="4" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Add clinician documentation for the telehealth consultation."></textarea>
                </div>

                @if ($isSessionDoctor && $session->intake)
                    <section class="space-y-4 rounded-xl border border-indigo-200 bg-indigo-50 p-6">
                        <div>
                            <h2 class="font-semibold text-indigo-900">AI consultation summary</h2>
                            <p class="mt-1 text-sm text-indigo-800">AI-generated draft, doctor must review. Nothing is added to the patient record until you approve it.</p>
                        </div>
                        @if ($session->aiSummary?->status === \App\Models\TelehealthAiSummary::STATUS_APPROVED)
                            <div class="rounded-lg bg-white p-4">
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-emerald-700">Doctor approved</p>
                                <p class="whitespace-pre-line text-sm text-slate-700">{{ $session->aiSummary->summary_text }}</p>
                            </div>
                        @elseif ($session->aiSummary)
                            <p class="text-xs font-medium text-amber-800">Draft — not saved to the patient record.</p>
                            <form method="POST" action="{{ route('telehealth.ai-summary.update', $session) }}" class="space-y-3">
                                @csrf
                                @method('PATCH')
                                <label for="summary_text" class="block text-sm font-medium text-slate-700">Review and edit draft</label>
                                <textarea id="summary_text" name="summary_text" rows="8" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('summary_text', $session->aiSummary->summary_text) }}</textarea>
                                @error('summary_text')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                                <button type="submit" class="rounded-lg border border-indigo-700 px-4 py-2 text-sm font-semibold text-indigo-800">Save edits</button>
                            </form>
                            <form method="POST" action="{{ route('telehealth.ai-summary.approve', $session) }}">
                                @csrf
                                <button type="submit" class="rounded-lg bg-indigo-700 px-4 py-2 text-sm font-semibold text-white">Approve and save to patient record</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('telehealth.ai-summary.generate', $session) }}" class="space-y-3">
                                @csrf
                                <label for="doctor_notes" class="block text-sm font-medium text-slate-700">Doctor's consultation notes</label>
                                <textarea id="doctor_notes" name="doctor_notes" rows="4" required minlength="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Enter clinical notes to include in the draft.">{{ old('doctor_notes') }}</textarea>
                                @error('doctor_notes')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                                <button type="submit" class="rounded-lg bg-indigo-700 px-4 py-2 text-sm font-semibold text-white">Generate draft</button>
                            </form>
                        @endif
                    </section>
                @endif
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700" onclick="window.location.reload()">Reset</button>
                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save closeout</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    (function () {
        const form = document.getElementById('closeoutForm');
        const token = document.querySelector('meta[name="csrf-token"]')?.content;

        if (!form) return;

        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            const payload = {
                assessment: document.getElementById('assessment')?.value || null,
                plan: document.getElementById('plan')?.value || null,
                discharge_instructions: document.getElementById('discharge_instructions')?.value || null,
                clinic_note: document.getElementById('clinic_note')?.value || null,
            };

            try {
                const response = await fetch('/api/v1/telehealth/{{ $session->id }}/closeout', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token || '',
                        'X-XSRF-TOKEN': document.cookie
                            .split('; ')
                            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                            ?.split('=')[1]
                            ? decodeURIComponent(document.cookie
                                .split('; ')
                                .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                                ?.split('=')[1] || '')
                            : '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });

                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data.message || 'Unable to save the consultation closeout.');
                }

                if (window.hisToast) {
                    hisToast('Telehealth consultation closeout saved.', 'success');
                }

                window.location.href = "{{ route('telehealth.index') }}";
            } catch (error) {
                if (window.hisToast) {
                    hisToast(error.message, 'danger');
                }
            }
        });
    })();
</script>
@endpush
@endsection
