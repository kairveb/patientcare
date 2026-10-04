@extends('layouts.hims')

@section('title', 'ER Visit')

@section('content')
@php($isQueued = $visit->queue !== null)
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-800">ER Visit {{ $visit->visit_number }}</h1>
        <p class="text-sm text-slate-500 mt-1">Patient: <span class="font-medium">{{ $visit->patient->full_name ?? '—' }}</span> · {{ $visit->patient->mrn ?? '' }}</p>
    </div>

    @include('emergency._workflow-steps', ['currentStep' => $isQueued ? 4 : 3])

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Visit details --}}
        <div class="bg-white rounded-xl border border-slate-200 p-6 {{ $isQueued ? 'lg:order-1' : 'lg:order-2' }}">
            <h3 class="font-semibold text-slate-800 mb-4">Arrival Information</h3>

            <div class="grid grid-cols-2 gap-4 text-sm">
                <div><span class="text-slate-500">Arrived:</span> <span class="font-medium">{{ $visit->arrived_at->format('M d, Y g:i A') }}</span></div>
                <div><span class="text-slate-500">Method:</span> <span class="font-medium">{{ $visit->arrival_method ?? '—' }}</span></div>
                <div class="col-span-2"><span class="text-slate-500">Complaint:</span> <span class="font-medium">{{ $visit->chief_complaint }}</span></div>
                <div class="col-span-2"><span class="text-slate-500">Status:</span> <span class="px-2 py-1 text-xs rounded-full bg-slate-100 ml-1">{{ $visit->status }}</span></div>
            </div>

            @if ($isQueued)
            <div class="mt-4 p-3 bg-slate-50 rounded-lg">
                <h4 class="text-sm font-medium text-slate-700 mb-2">ER Queue</h4>
                <div class="flex justify-between text-sm">
                    <span>Priority: <strong>{{ $visit->queue->priority }}</strong></span>
                    <span>Status: {{ $visit->queue->status }}</span>
                    <span>Area: {{ $visit->queue->treatment_area ?? '—' }}</span>
                </div>
                <form method="POST" action="{{ route('emergency.queue-status', $visit->queue) }}" class="mt-3 flex gap-2">
                    @csrf
                    <select name="status" class="px-3 py-2 text-sm border border-slate-300 rounded-lg">
                        <option value="WAITING" {{ $visit->queue->status === 'WAITING' ? 'selected' : '' }}>Waiting</option>
                        <option value="IN_TREATMENT" {{ $visit->queue->status === 'IN_TREATMENT' ? 'selected' : '' }}>In Treatment</option>
                        @if ($canCompleteErVisit || $visit->queue->status === 'DONE')
                            <option value="DONE" {{ $visit->queue->status === 'DONE' ? 'selected' : '' }}>Done</option>
                        @endif
                    </select>
                    <select name="provider_id" class="px-3 py-2 text-sm border border-slate-300 rounded-lg">
                        <option value="">Provider</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider->id }}">{{ $provider->full_name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="px-4 py-2 text-sm bg-slate-800 text-white rounded-lg">Update</button>
                </form>
                @if (! $canCompleteErVisit && $dischargeRequirements)
                    <div role="status" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                        <p class="font-semibold">Completion is unavailable until:</p>
                        <ul class="mt-1 list-disc pl-5">@foreach ($dischargeRequirements as $requirement)<li>{{ $requirement }}</li>@endforeach</ul>
                    </div>
                @endif
            </div>
            @endif
        </div>

        @if ($visit->queue && $visit->queue->provider?->user_id === auth()->id() && auth()->user()->hasRole('doctor'))
            <section class="rounded-xl border border-slate-200 bg-white p-6">
                <h3 class="font-semibold text-slate-800">ER Discharge Summary</h3>
                @if ($visit->dischargeSummary?->status === \App\Models\DischargeSummary::STATUS_APPROVED)
                    <p class="mt-2 text-sm text-emerald-700">Approved by the assigned physician.</p>
                @else
                    <form method="POST" action="{{ route('emergency.discharge-summary.store', $visit) }}" class="mt-4 space-y-3">
                        @csrf
                        <label class="block text-sm">Final diagnosis<textarea name="final_diagnosis" required maxlength="10000" rows="2" class="mt-1 w-full rounded border-slate-300">{{ old('final_diagnosis', $visit->dischargeSummary?->final_diagnosis) }}</textarea></label>
                        <label class="block text-sm">Clinical course<textarea name="clinical_course" required maxlength="30000" rows="3" class="mt-1 w-full rounded border-slate-300">{{ old('clinical_course', $visit->dischargeSummary?->clinical_course) }}</textarea></label>
                        <label class="block text-sm">Discharge instructions<textarea name="discharge_instructions" required maxlength="30000" rows="3" class="mt-1 w-full rounded border-slate-300">{{ old('discharge_instructions', $visit->dischargeSummary?->discharge_instructions) }}</textarea></label>
                        <label class="block text-sm">Follow-up plan<textarea name="follow_up_plan" maxlength="10000" rows="2" class="mt-1 w-full rounded border-slate-300">{{ old('follow_up_plan', $visit->dischargeSummary?->follow_up_plan) }}</textarea></label>
                        <div class="flex gap-2">
                            <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Save draft</button>
                    </form>
                    @if ($visit->dischargeSummary)
                        <form method="POST" action="{{ route('emergency.discharge-summary.approve', $visit) }}">
                            @csrf
                            <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Approve summary</button>
                        </form>
                    @endif
                        </div>
                @endif
            </section>
        @endif

        {{-- Triage / queue status: launches the modal that actually adds the patient to er_queue --}}
        <div class="rounded-xl border p-6 {{ $isQueued ? 'lg:order-2 border-slate-200 bg-white' : 'lg:order-1 border-rose-300 bg-rose-50/60 shadow-sm' }}">
            <div class="mb-4 flex items-start justify-between gap-3">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.14em] {{ $visit->queue ? 'text-slate-500' : 'text-rose-600' }}">Step 3 of 3</p>
                    <h3 class="font-semibold text-slate-800">Confirm priority to add to active queue</h3>
                </div>
                @if ($isQueued)
                    <span class="whitespace-nowrap rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-emerald-700">Queued</span>
                @else
                    <span class="whitespace-nowrap rounded-full bg-rose-600 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-white">Action required</span>
                @endif
            </div>

            @if ($visit->queue)
                <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                    <p class="font-semibold">Priority {{ $visit->queue->priority }} · {{ $visit->queue->status }}</p>
                    <p class="mt-0.5 text-emerald-700">This patient is already in the active ER queue.</p>
                </div>
                <button type="button" class="inline-flex items-center justify-center rounded-xl border border-rose-300 bg-white px-4 py-2.5 text-sm font-semibold text-rose-600 shadow-sm transition hover:bg-rose-50" data-bs-toggle="modal" data-bs-target="#triageQueueModal">Adjust priority / clinical override</button>
            @else
                <p class="mb-4 text-sm leading-6 text-rose-700">This patient hasn't been triaged yet and won't appear in the active ER queue until you confirm a priority.</p>
                <button type="button" class="inline-flex items-center justify-center rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-700" data-bs-toggle="modal" data-bs-target="#triageQueueModal">Confirm priority to add to queue</button>
            @endif

            @if ($visit->triageAssessments->isNotEmpty() && $visit->status !== \App\Models\ErVisit::STATUS_ADMITTED)
                <div class="mt-4 pt-4 border-t border-slate-200">
                    <a href="{{ route('emergency.admission.create', $visit) }}" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                        Convert to Admission
                    </a>
                </div>
            @endif
        </div>
    </div>

    <div class="modal fade" id="triageQueueModal" tabindex="-1" aria-labelledby="triageQueueModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-2xl">
                <div class="modal-header border-b border-slate-200 px-5 py-4">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-rose-600">Step 3 of 3</p>
                        <h5 class="modal-title font-semibold text-slate-800" id="triageQueueModalLabel">Confirm priority to add to active queue</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body px-5 py-5">
                    @include('emergency.partials.queue-form')
                    @if (false)
                    @if ($errors->any())
                        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm leading-6 text-red-800">
                            <strong>Please fix the following errors:</strong>
                            <ul class="mt-2 list-disc list-inside">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

            <form method="POST" action="{{ route('emergency.triage', $visit) }}" data-patient-id="{{ $visit->patient_id }}" class="space-y-4">
                @csrf

                <input type="hidden" name="patient_id" value="{{ $visit->patient_id }}">
                <input type="hidden" name="ai_confirmed" id="ai_confirmed" value="0">
                <input type="hidden" name="priority_override" id="priority_override" value="">

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Chief Complaint</label>
                    <textarea name="chief_complaint" rows="2" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">{{ $visit->chief_complaint }}</textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Symptoms</label>
                    <input type="text" name="symptoms" placeholder="e.g. chest pain, shortness of breath, dizziness" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Pain Score (0-10)</label>
                        <input type="number" name="pain_score" min="0" max="10" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Priority *</label>
                        <select name="priority" id="priority_select" required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                            <option value="">Select</option>
                            <option value="Level 1">Level 1 — Critical</option>
                            <option value="Level 2">Level 2</option>
                            <option value="Level 3">Level 3</option>
                            <option value="Level 4">Level 4</option>
                            <option value="Level 5">Level 5 — Non-urgent</option>
                        </select>
                    </div>
                </div>

                <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4" id="ai-preview">
                    <div class="text-sm leading-6 text-slate-600">No assessment has been run yet. Complete the intake details and generate a priority recommendation.</div>
                </div>

                <div class="grid gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                    <div class="flex items-center justify-between"><span class="font-medium text-slate-600">Severity score</span><strong id="severity-score-display">—</strong></div>
                    <div class="flex items-center justify-between"><span class="font-medium text-slate-600">Priority band</span><strong id="priority-band-display">—</strong></div>
                </div>

                @can('triage-patients')
                    <div class="flex flex-wrap gap-3">
                        <button type="button" id="run-ai" class="px-4 py-2.5 text-sm font-medium bg-red-600 text-white rounded-lg hover:bg-red-700">Generate recommendation</button>
                        <button type="button" id="clinical-override" class="px-4 py-2.5 text-sm font-medium border border-slate-300 bg-white text-slate-700 rounded-lg hover:bg-slate-100">Clinical override</button>
                    </div>
                @endcan

                <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                    <input type="checkbox" id="ai-confirmed-toggle" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                    <span>I confirm the recommended priority, or I have applied a clinical override and documented the reason.</span>
                </label>

                <div>
                    <h4 class="text-sm font-medium text-slate-700 mb-2">Vital Signs</h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="block text-xs text-slate-500 mb-1">Blood Pressure</label><input name="vitals_blood_pressure" placeholder="120/80" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"></div>
                        <div><label class="block text-xs text-slate-500 mb-1">Heart Rate</label><input type="number" name="vitals_heart_rate" placeholder="bpm" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"></div>
                        <div><label class="block text-xs text-slate-500 mb-1">Respiratory Rate</label><input type="number" name="vitals_respiratory_rate" placeholder="/min" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"></div>
                        <div><label class="block text-xs text-slate-500 mb-1">Temperature (°C)</label><input type="number" step="0.1" name="vitals_temperature" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"></div>
                        <div><label class="block text-xs text-slate-500 mb-1">SpO2 (%)</label><input type="number" name="vitals_spo2" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"></div>
                        <div><label class="block text-xs text-slate-500 mb-1">Glasgow Coma Scale (3-15)</label><input type="number" name="gcs" min="3" max="15" step="1" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"></div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Treatment Area</label>
                    <input type="text" name="treatment_area" placeholder="Resus, Bay 1..." class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"></textarea>
                </div>

                <button type="submit" class="px-6 py-2.5 text-sm font-medium bg-red-600 text-white rounded-lg hover:bg-red-700">Complete Triage</button>
            </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Triage history --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200">
            <h3 class="font-semibold text-slate-800">Triage History</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500 bg-slate-50 border-b border-slate-200">
                        <th class="py-3 px-4">Time</th><th class="py-3 px-4">Priority</th><th class="py-3 px-4">Pain</th><th class="py-3 px-4">Vitals</th><th class="py-3 px-4">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($visit->triageAssessments as $ta)
                        <tr class="border-b border-slate-100">
                            <td class="py-3 px-4">{{ $ta->triaged_at->format('M d, g:i A') }}</td>
                            <td class="py-3 px-4"><span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700">{{ $ta->priority }}</span></td>
                            <td class="py-3 px-4">{{ $ta->pain_score ?? '—' }}/10</td>
                            <td class="py-3 px-4 text-xs">
                                @if ($ta->triageVital)
                                    BP {{ $ta->triageVital->blood_pressure ?? '—' }} · HR {{ $ta->triageVital->heart_rate ?? '—' }} · T {{ $ta->triageVital->temperature ?? '—' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="py-3 px-4">{{ $ta->notes ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-slate-400">No triage assessments yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="font-semibold text-slate-800">AI Reassessment History</h3>
            <p class="mt-1 text-sm text-slate-500">New vital readings are scored as decision support and never change the final level without a Nurse or Doctor confirmation.</p>
        </div>

        @if (auth()->user()->hasRole('nurse') && in_array($visit->status, [\App\Models\ErVisit::STATUS_ARRIVED, \App\Models\ErVisit::STATUS_TRIAGED, \App\Models\ErVisit::STATUS_IN_TREATMENT], true) && $visit->triageAssessments->isNotEmpty())
            <form method="POST" action="{{ route('emergency.reassessments.store', $visit) }}" class="space-y-4 border-b border-slate-200 bg-slate-50 p-5">
                @csrf
                <h4 class="font-medium text-slate-800">Record new vital signs</h4>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="text-xs text-slate-600">Blood pressure
                        <input name="vitals[blood_pressure]" required maxlength="20" placeholder="120/80" value="{{ old('vitals.blood_pressure') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </label>
                    <label class="text-xs text-slate-600">Heart rate (bpm)
                        <input type="number" name="vitals[heart_rate]" min="1" max="300" required value="{{ old('vitals.heart_rate') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </label>
                    <label class="text-xs text-slate-600">Respiratory rate (/min)
                        <input type="number" name="vitals[respiratory_rate]" min="1" max="100" required value="{{ old('vitals.respiratory_rate') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </label>
                    <label class="text-xs text-slate-600">Temperature (°C)
                        <input type="number" name="vitals[temperature]" min="25" max="45" step="0.1" required value="{{ old('vitals.temperature') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </label>
                    <label class="text-xs text-slate-600">SpO₂ (%)
                        <input type="number" name="vitals[spo2]" min="0" max="100" step="0.1" required value="{{ old('vitals.spo2') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </label>
                    <label class="text-xs text-slate-600">GCS (3–15)
                        <input type="number" name="vitals[gcs]" min="3" max="15" value="{{ old('vitals.gcs') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </label>
                </div>
                <button type="submit" class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800">Save vitals and request AI reassessment</button>
            </form>
        @elseif ($visit->triageAssessments->isEmpty())
            <p class="p-5 text-sm text-slate-500">Complete the initial triage assessment before recording a reassessment.</p>
        @endif

        <div class="divide-y divide-slate-100">
            @forelse ($visit->triageReassessments->sortByDesc('recorded_at') as $reassessment)
                <article class="space-y-3 p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold text-slate-800">{{ $reassessment->recorded_at->format('M d, Y g:i A') }} · {{ $reassessment->recordedBy?->name ?? 'Staff' }}</p>
                            <p class="mt-1 text-sm text-slate-600">AI suggestion: <strong>{{ $reassessment->ai_suggested_level }}</strong> · Current final when recorded: <strong>{{ $reassessment->current_final_level }}</strong></p>
                            <p class="mt-1 text-sm {{ $reassessment->more_severe ? 'font-semibold text-red-700' : 'text-slate-600' }}">
                                {{ $reassessment->more_severe ? 'More severe than current final level — clinician review alerted.' : 'No increase in acuity suggested.' }}
                            </p>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $reassessment->status === \App\Models\TriageReassessment::STATUS_PENDING ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">{{ $reassessment->status }}</span>
                    </div>

                    <p class="text-xs text-slate-600">
                        BP {{ $reassessment->vitals['blood_pressure'] ?? '—' }} · HR {{ $reassessment->vitals['heart_rate'] ?? '—' }} · RR {{ $reassessment->vitals['respiratory_rate'] ?? '—' }} · Temp {{ $reassessment->vitals['temperature'] ?? '—' }} · SpO₂ {{ $reassessment->vitals['spo2'] ?? '—' }} · GCS {{ $reassessment->vitals['gcs'] ?? '—' }}
                    </p>

                    <ul class="list-inside list-disc text-sm text-slate-600">
                        @foreach ($reassessment->ai_reasons ?? [] as $reason)
                            <li>{{ $reason }}</li>
                        @endforeach
                    </ul>

                    @if ($reassessment->status === \App\Models\TriageReassessment::STATUS_PENDING && auth()->user()->hasRole('nurse', 'doctor'))
                        <form method="POST" action="{{ route('emergency.reassessments.confirm', [$visit, $reassessment]) }}" class="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2">
                            @csrf
                            <label class="text-sm font-medium text-slate-700">Confirmed final level
                                <select name="final_level" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                    @foreach (['Emergency', 'Urgent', 'Prompt', 'Non-Urgent', 'Routine'] as $level)
                                        <option value="{{ $level }}" @selected($reassessment->ai_suggested_level === $level)>{{ $level }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="text-sm font-medium text-slate-700">Override reason <span class="font-normal text-slate-500">(required if final level differs from AI)</span>
                                <textarea name="override_reason" rows="2" maxlength="2000" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Document the clinical reason (minimum 10 characters)"></textarea>
                            </label>
                            <div class="sm:col-span-2">
                                <button type="submit" class="rounded-lg bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">Confirm clinical decision</button>
                            </div>
                        </form>
                    @elseif ($reassessment->status === \App\Models\TriageReassessment::STATUS_CONFIRMED)
                        <p class="text-sm text-emerald-800">Final level: <strong>{{ $reassessment->final_level }}</strong> · Confirmed by {{ $reassessment->confirmedBy?->name ?? 'Staff' }} at {{ $reassessment->confirmed_at?->format('M d, Y g:i A') }}</p>
                        @if ($reassessment->override_reason)
                            <p class="text-sm text-slate-600">Override reason: {{ $reassessment->override_reason }}</p>
                        @endif
                    @endif
                </article>
            @empty
                <p class="p-5 text-sm text-slate-500">No re-triage vitals have been recorded.</p>
            @endforelse
        </div>
    </section>
</div>

@push('scripts')
@if (!$isQueued)
<script>
    (function () {
        const modalEl = document.getElementById('triageQueueModal');
        if (!modalEl || typeof bootstrap === 'undefined') {
            return;
        }

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        requestAnimationFrame(() => {
            modal.show();
        });
    })();
</script>
@endif
<script>
    (function () {
        const form = document.querySelector('form[data-patient-id]');
        if (!form) return;

        const preview = document.getElementById('ai-preview');
        const runAiButton = document.getElementById('run-ai');
        const severityDisplay = document.getElementById('severity-score-display');
        const bandDisplay = document.getElementById('priority-band-display');
        const aiConfirmedToggle = document.getElementById('ai-confirmed-toggle');
        const aiConfirmedField = document.getElementById('ai_confirmed');
        const priorityOverrideField = document.getElementById('priority_override');
        const selectField = document.getElementById('priority_select');
        const overrideReasonField = form.querySelector('[name="override_reason"]');
        const overrideReasonDisplay = document.getElementById('override_reason_display');
        const overrideReasonContainer = document.getElementById('override-reason-field');
        let suggestedPriority = selectField?.dataset.aiSuggested || null;
        const overrideModal = document.createElement('div');
        // Bootstrap modal/backdrop use z-index ~1040-1055; use an inline style (not a Tailwind
        // arbitrary class, which may not exist in the precompiled CSS) so this reliably renders on top.
        overrideModal.className = 'fixed inset-0 hidden items-center justify-center bg-slate-900/40 p-4';
        overrideModal.style.zIndex = '1080';
        overrideModal.innerHTML = `
            <div class="w-full max-w-lg rounded-3xl border border-slate-200 bg-white p-5 shadow-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 pb-4">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-amber-600">Clinical safety</p>
                        <h3 class="mt-1 text-xl font-semibold text-slate-900">Clinical override</h3>
                    </div>
                    <button type="button" class="close-override rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100">Close</button>
                </div>
                <div class="mt-5 space-y-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Override priority</label>
                        <select id="override-level" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            <option value="Emergency">Emergency — immediate escalation</option>
                            <option value="Urgent">Urgent — rapid review</option>
                            <option value="Prompt">Prompt — timely assessment</option>
                            <option value="Non-Urgent">Non-Urgent — standard review</option>
                            <option value="Routine">Routine — routine follow-up</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Override rationale</label>
                        <textarea id="override-notes" rows="4" minlength="10" placeholder="Explain why the AI recommendation was adjusted by the clinical team." class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"></textarea>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" class="cancel-override inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button>
                        <button type="button" class="apply-override inline-flex items-center justify-center rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-semibold text-slate-900 shadow-sm transition hover:bg-amber-400">Apply override</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(overrideModal);

        function syncAiConfirmationState(checked) {
            if (aiConfirmedField) aiConfirmedField.value = checked ? '1' : '0';
            if (aiConfirmedToggle) aiConfirmedToggle.checked = checked;
        }

        function normalizeLevel(level) {
            const normalized = String(level || '').toLowerCase().trim();
            return ({
                'level 1': 'emergency', emergency: 'emergency', red: 'emergency',
                'level 2': 'urgent', urgent: 'urgent', yellow: 'urgent',
                'level 3': 'prompt', prompt: 'prompt', orange: 'prompt',
                'level 4': 'non-urgent', 'non-urgent': 'non-urgent', green: 'non-urgent',
                'level 5': 'routine', routine: 'routine',
            })[normalized] || normalized;
        }

        function syncOverrideReason() {
            const selected = priorityOverrideField?.value || selectField?.value || '';
            const isOverride = suggestedPriority !== null && normalizeLevel(selected) !== normalizeLevel(suggestedPriority);
            overrideReasonContainer?.classList.toggle('hidden', !isOverride);
            if (overrideReasonDisplay) overrideReasonDisplay.required = isOverride;
            if (!isOverride) {
                if (overrideReasonField) overrideReasonField.value = '';
                if (overrideReasonDisplay) overrideReasonDisplay.value = '';
            }
        }

        selectField?.addEventListener('change', function () {
            if (priorityOverrideField) priorityOverrideField.value = this.value;
            syncOverrideReason();
        });

        overrideReasonDisplay?.addEventListener('input', function () {
            if (overrideReasonField) overrideReasonField.value = this.value;
        });

        function closeOverrideModal() {
            overrideModal.classList.add('hidden');
            overrideModal.classList.remove('flex');
        }

        function apiRequest(url, method = 'GET', payload = null) {
            const options = {
                method,
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            };
            if (payload !== null) {
                options.body = JSON.stringify(payload);
                options.headers['Content-Type'] = 'application/json';
            }
            return window.HimsApi?.request?.(url, options) ?? fetch(url, options).then(async (response) => {
                const text = await response.text();
                let data = null;
                try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
                if (!response.ok) {
                    throw new Error(data?.message || 'Request failed.');
                }
                return { data: data?.data ?? data };
            });
        }

        function renderAssessment(result) {
            const priority = result.priority || 'Routine';
            const confidence = result.confidence || 80;
            const color = result.color || 'green';
            const severityScore = result.severity_score ?? 0;
            const priorityBand = result.priority_band || 'Green';
            const badgeClasses = {
                red: 'bg-rose-600 text-white',
                yellow: 'bg-amber-500 text-slate-900',
                orange: 'bg-orange-500 text-white',
                green: 'bg-emerald-600 text-white',
            };
            const reasons = (result.reasons || []).map((reason) => `<li>${reason}</li>`).join('');

            if (severityDisplay) severityDisplay.textContent = `${severityScore}/100`;
            if (bandDisplay) {
                bandDisplay.textContent = priorityBand;
                bandDisplay.className = 'font-semibold ' + (priorityBand === 'Red' ? 'text-rose-600' : priorityBand === 'Yellow' ? 'text-amber-600' : 'text-emerald-600');
            }
            suggestedPriority = priority;
            if (priorityOverrideField) priorityOverrideField.value = priority;
            if (selectField) selectField.value = priority === 'Emergency' ? 'Level 1' : priority === 'Urgent' ? 'Level 2' : priority === 'Prompt' ? 'Level 3' : priority === 'Non-Urgent' ? 'Level 4' : 'Level 5';
            syncOverrideReason();
            syncAiConfirmationState(false);

            if (preview) {
                preview.innerHTML = `
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.12em] ${badgeClasses[color] || badgeClasses.green}">${priority}</span>
                        <span class="text-xs font-medium text-slate-500">Confidence ${confidence}%</span>
                    </div>
                    <div class="space-y-3 text-sm text-slate-700">
                        <p class="text-base font-semibold text-slate-900">AI recommendation</p>
                        <p class="leading-6">${result.recommendation || 'No specific concerns identified.'}</p>
                        <ul class="list-disc space-y-1 pl-5 text-slate-600">${reasons || '<li>No acute red flags were detected.</li>'}</ul>
                    </div>
                `;
            }
        }

        if (aiConfirmedToggle) {
            aiConfirmedToggle.addEventListener('change', function () {
                syncAiConfirmationState(this.checked);
            });
        }

        if (runAiButton) {
            runAiButton.addEventListener('click', async function () {
                const complaint = form.querySelector('[name="chief_complaint"]')?.value?.trim();
                const symptoms = (form.querySelector('[name="symptoms"]')?.value ?? '')
                    .split(/[\n,;]/)
                    .map((value) => value.trim())
                    .filter(Boolean);

                if (!complaint) {
                    if (window.hisToast) hisToast('Enter a chief complaint before running AI triage.', 'warning');
                    return;
                }

                try {
                    const response = await apiRequest('/api/v1/triage/score', 'POST', {
                        patient_id: Number(form.dataset.patientId || form.querySelector('[name="patient_id"]').value),
                        chief_complaint: complaint,
                        pain_score: Number(form.querySelector('[name="pain_score"]')?.value || 0),
                        symptoms,
                        vitals: {
                            blood_pressure: form.querySelector('[name="vitals_blood_pressure"]')?.value || null,
                            heart_rate: Number(form.querySelector('[name="vitals_heart_rate"]')?.value || 0) || null,
                            respiratory_rate: Number(form.querySelector('[name="vitals_respiratory_rate"]')?.value || 0) || null,
                            temperature: Number(form.querySelector('[name="vitals_temperature"]')?.value || 0) || null,
                            spo2: Number(form.querySelector('[name="vitals_spo2"]')?.value || 0) || null,
                            gcs: Number(form.querySelector('[name="gcs"]')?.value || 0) || null,
                        },
                    });

                    renderAssessment(response.data || {});

                    if (window.hisToast) hisToast('AI triage completed. ' + (response.data?.priority || 'Routine') + ' priority assigned.', 'success');
                } catch (error) {
                    if (window.hisToast) hisToast(error.message, 'danger');
                }
            });
        }

        document.getElementById('clinical-override')?.addEventListener('click', function () {
            overrideModal.classList.remove('hidden');
            overrideModal.classList.add('flex');
        });

        overrideModal.querySelector('.close-override')?.addEventListener('click', closeOverrideModal);
        overrideModal.querySelector('.cancel-override')?.addEventListener('click', closeOverrideModal);
        overrideModal.querySelector('.apply-override')?.addEventListener('click', function () {
            const selectedPriority = overrideModal.querySelector('#override-level')?.value || 'Routine';
            const rationale = overrideModal.querySelector('#override-notes')?.value?.trim() || '';
            if (rationale.length < 10) {
                if (window.hisToast) hisToast('Enter an override reason of at least 10 characters.', 'warning');
                return;
            }
            const notesField = form.querySelector('[name="notes"]');

            if (notesField) {
                notesField.value = `Clinical override applied: ${selectedPriority}. Reason: ${rationale}`;
            }
            if (priorityOverrideField) priorityOverrideField.value = selectedPriority;
            if (selectField) selectField.value = selectedPriority === 'Emergency' ? 'Level 1' : selectedPriority === 'Urgent' ? 'Level 2' : selectedPriority === 'Prompt' ? 'Level 3' : selectedPriority === 'Non-Urgent' ? 'Level 4' : 'Level 5';
            if (overrideReasonField) overrideReasonField.value = rationale;
            if (overrideReasonDisplay) overrideReasonDisplay.value = rationale;
            syncOverrideReason();
            syncAiConfirmationState(true);

            if (bandDisplay) {
                bandDisplay.textContent = selectedPriority === 'Emergency' ? 'Red' : selectedPriority === 'Urgent' ? 'Yellow' : selectedPriority === 'Prompt' ? 'Green' : selectedPriority === 'Non-Urgent' ? 'Green' : 'Green';
                bandDisplay.className = 'font-semibold ' + (selectedPriority === 'Emergency' ? 'text-rose-600' : selectedPriority === 'Urgent' ? 'text-amber-600' : 'text-emerald-600');
            }

            if (severityDisplay) severityDisplay.textContent = selectedPriority === 'Emergency' ? '96/100' : selectedPriority === 'Urgent' ? '85/100' : selectedPriority === 'Prompt' ? '68/100' : '46/100';

            if (preview) {
                preview.innerHTML = `
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <span class="inline-flex rounded-full bg-amber-500 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-900">${selectedPriority}</span>
                        <span class="text-xs font-medium text-slate-500">Clinician override</span>
                    </div>
                    <div class="space-y-3 text-sm text-slate-700">
                        <p class="text-base font-semibold text-slate-900">Clinical review</p>
                        <p class="leading-6">The AI suggestion was reviewed and adjusted by the assigned clinical team to ${selectedPriority} priority.</p>
                        <p class="rounded-xl border border-amber-200 bg-amber-50 p-2 text-slate-700">${rationale}</p>
                    </div>
                `;
            }

            if (window.hisToast) hisToast('Clinical override saved to the triage notes.', 'success');
            closeOverrideModal();
        });
    })();
</script>
@endpush
@endsection
