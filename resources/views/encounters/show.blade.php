@extends('layouts.hims')

@section('title', 'Encounter Detail')

@section('content')
<div class="max-w-4xl space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Encounter {{ $encounter->encounter_number }}</h1>
            <p class="text-sm text-slate-500 mt-1">Patient: <span class="font-medium">{{ $encounter->patient->full_name ?? '—' }}</span> · {{ $encounter->patient->mrn ?? '' }}</p>
        </div>
        <div class="flex gap-2">
            @can('complete', $encounter)
                @if ($encounter->status === 'OPEN')
                    <a href="#encounter-completion-form" class="px-4 py-2 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700">Complete Encounter</a>
                @endif
            @endcan
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div><span class="text-slate-500">Provider:</span> <span class="font-medium">{{ $encounter->provider->full_name ?? '—' }}</span></div>
            <div><span class="text-slate-500">Type:</span> <span class="px-2 py-1 text-xs rounded-full bg-slate-100">{{ $encounter->type }}</span></div>
            <div><span class="text-slate-500">Status:</span> <span class="px-2 py-1 text-xs rounded-full bg-slate-100">{{ $encounter->status }}</span></div>
            <div class="col-span-1"><span class="text-slate-500">Started:</span> {{ $encounter->started_at->format('M d, Y g:i A') }}</div>
            @if ($encounter->appointment)
                <div class="col-span-2"><span class="text-slate-500">Appointment:</span> <span class="font-mono">{{ $encounter->appointment->appointment_number }}</span></div>
            @endif
        </div>
    </div>

    @if ($encounter->chief_complaint)
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <h3 class="font-semibold text-slate-800 mb-2">Chief Complaint</h3>
        <p class="text-sm text-slate-600">{{ $encounter->chief_complaint }}</p>
    </div>
    @endif

    @if ($encounter->vitals->isNotEmpty())
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <h3 class="font-semibold text-slate-800 mb-3">Vital Signs</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            @php $v = $encounter->vitals->last(); @endphp
            <div><span class="text-slate-500">BP</span><div class="font-medium">{{ $v->blood_pressure ?? '—' }}</div></div>
            <div><span class="text-slate-500">HR</span><div class="font-medium">{{ $v->heart_rate ?? '—' }}</div></div>
            <div><span class="text-slate-500">RR</span><div class="font-medium">{{ $v->respiratory_rate ?? '—' }}</div></div>
            <div><span class="text-slate-500">Temp</span><div class="font-medium">{{ $v->temperature ?? '—' }}</div></div>
            <div><span class="text-slate-500">SpO2</span><div class="font-medium">{{ $v->spo2 ?? '—' }}</div></div>
            <div><span class="text-slate-500">Height</span><div class="font-medium">{{ $v->height_cm ? $v->height_cm.' cm' : '—' }}</div></div>
            <div><span class="text-slate-500">Weight</span><div class="font-medium">{{ $v->weight ? $v->weight.' kg' : '—' }}</div></div>
            <div><span class="text-slate-500">BMI</span><div class="font-medium">{{ $v->bmi ?? '—' }}</div></div>
            <div><span class="text-slate-500">Pain score</span><div class="font-medium">{{ $v->pain_score ?? '—' }}</div></div>
        </div>
    </div>
    @endif

    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-800 mb-2">History of Present Illness</h3>
            <p class="whitespace-pre-line text-sm text-slate-600">{{ $encounter->history_of_present_illness ?: 'Not documented' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-800 mb-2">Physical Examination</h3>
            <p class="whitespace-pre-line text-sm text-slate-600">{{ $encounter->physical_examination ?: 'Not documented' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-6 md:col-span-2">
            <h3 class="font-semibold text-slate-800 mb-2">Primary Diagnosis</h3>
            <p class="text-sm text-slate-600">{{ $encounter->primary_diagnosis_code ?: '—' }} — {{ $encounter->primary_diagnosis_description ?: 'Not documented' }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-800 mb-2">Assessment</h3>
            <p class="text-sm text-slate-600">{{ $encounter->assessment ?? '—' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-800 mb-2">Plan</h3>
            <p class="text-sm text-slate-600">{{ $encounter->plan ?? '—' }}</p>
        </div>
    </div>

    @can('create', [\App\Models\CareRequest::class, $encounter])
        <section class="rounded-xl border border-slate-200 bg-white p-6">
            <h3 class="font-semibold text-slate-800">Outpatient approval request</h3>
            <p class="mt-1 text-sm text-slate-500">Request approval for a lab, imaging service, or procedure.</p>
            <form method="POST" action="{{ route('encounters.care-requests.store', $encounter) }}" class="mt-4 space-y-4">
                @csrf
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="block text-sm font-medium text-slate-700">Service type
                        <select name="type" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="">Choose a type</option>
                            <option value="lab" @selected(old('type') === 'lab')>Laboratory</option>
                            <option value="imaging" @selected(old('type') === 'imaging')>Imaging</option>
                            <option value="procedure" @selected(old('type') === 'procedure')>Procedure</option>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-slate-700">Urgency
                        <select name="urgency" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="routine" @selected(old('urgency', 'routine') === 'routine')>Routine</option>
                            <option value="urgent" @selected(old('urgency') === 'urgent')>Urgent</option>
                        </select>
                    </label>
                </div>
                <label class="block text-sm font-medium text-slate-700">Requested service and clinical reason
                    <textarea name="description" required minlength="3" maxlength="5000" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('description') }}</textarea>
                </label>
                <button type="submit" class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800">Submit for approval</button>
            </form>
        </section>
    @endcan

    @if ($encounter->careRequests->isNotEmpty())
        <section class="rounded-xl border border-slate-200 bg-white p-6">
            <h3 class="font-semibold text-slate-800">Outpatient approval requests</h3>
            <div class="mt-3 divide-y divide-slate-100">
                @foreach ($encounter->careRequests as $careRequest)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div>
                            <p class="font-medium text-slate-800">{{ ucfirst($careRequest->type) }} · {{ $careRequest->status }}</p>
                            <p class="text-sm text-slate-600">{{ $careRequest->description }}</p>
                            @if ($careRequest->approval_reference)
                                <p class="text-xs text-slate-500">Reference: {{ $careRequest->approval_reference }}</p>
                            @endif
                        </div>
                        <a href="{{ route('care-requests.show', $careRequest) }}" class="text-sm font-medium text-teal-700 hover:underline">View request</a>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($encounter->discharge_instructions || $encounter->follow_up_date)
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <h3 class="font-semibold text-slate-800 mb-3">Discharge & follow-up</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            @if ($encounter->discharge_instructions)
                <div class="md:col-span-2">
                    <span class="text-slate-500 block mb-1">Discharge instructions</span>
                    <p class="text-slate-700">{{ $encounter->discharge_instructions }}</p>
                </div>
            @endif
            @if ($encounter->follow_up_date)
                <div>
                    <span class="text-slate-500 block mb-1">Follow-up date</span>
                    <p class="font-medium text-slate-700">{{ $encounter->follow_up_date->format('M d, Y') }}</p>
                </div>
            @endif
        </div>
    </div>
    @endif

    @can('update', $encounter)
        @if (auth()->user()->hasRole('doctor'))
            <section class="rounded-xl border border-blue-200 bg-blue-50 p-6">
                <h3 class="font-semibold text-blue-950">Recommend a follow-up appointment</h3>
                <p class="mt-1 text-sm text-blue-900">The suggested date is saved with this encounter. The patient can select a real open slot on your calendar.</p>
                <form method="POST" action="{{ route('encounters.follow-up', $encounter) }}" class="mt-4 grid gap-3 sm:grid-cols-[1fr_2fr_auto] sm:items-end">
                    @csrf
                    <label class="text-sm font-medium text-slate-700">Suggested date
                        <input type="date" name="follow_up_date" required min="{{ today()->toDateString() }}" value="{{ old('follow_up_date', $encounter->follow_up_date?->toDateString()) }}" class="mt-1 block w-full rounded-lg border-slate-300">
                    </label>
                    <label class="text-sm font-medium text-slate-700">Patient-facing note (optional)
                        <input type="text" name="recommendation" maxlength="1000" class="mt-1 block w-full rounded-lg border-slate-300">
                    </label>
                    <button class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Send recommendation</button>
                </form>
            </section>
        @endif
    @endcan

    @if ($encounter->notes->isNotEmpty())
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <h3 class="font-semibold text-slate-800 mb-3">Clinical Notes</h3>
        <div class="space-y-3">
            @foreach ($encounter->notes as $note)
                <div class="p-3 bg-slate-50 rounded-lg">
                    <div class="flex justify-between text-xs text-slate-500 mb-1">
                        <span>{{ $note->author->name ?? 'System' }}</span>
                        <span>{{ $note->created_at->format('M d, Y g:i A') }}</span>
                    </div>
                    <p class="text-sm text-slate-700">{{ $note->content }}</p>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <h3 class="font-semibold text-slate-800 mb-3">Prescriptions</h3>
        @if ($encounter->prescriptions->isEmpty())
            <p class="text-sm text-slate-500">No prescriptions linked to this encounter.</p>
        @else
            <div class="space-y-3">
                @foreach ($encounter->prescriptions as $prescription)
                    <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
                        <p class="font-medium text-slate-800">{{ $prescription->medication_name }}</p>
                        <p class="text-sm text-slate-600">{{ $prescription->dosage }}</p>
                        <p class="mt-1 text-sm text-slate-700">{{ $prescription->instructions }}</p>
                        <p class="mt-1 text-xs text-slate-500">Prescribed {{ $prescription->prescribed_at?->format('M d, Y g:i A') ?? '—' }} by {{ $prescription->prescribedBy?->name ?? 'Clinical team' }}</p>
                        <p class="mt-1 text-xs text-slate-500">Dispensing: {{ $prescription->dispensed_at?->format('M d, Y g:i A') ?? 'Not dispensed' }}</p>
                        @role('super-admin')
                            @if (! $prescription->dispensed_at)
                                <form method="POST" action="{{ route('prescriptions.dispense', $prescription) }}" class="mt-2">
                                    @csrf
                                    <button class="rounded bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white">Mark dispensed</button>
                                </form>
                            @endif
                        @endrole
                    </div>
                @endforeach
            </div>

            @role('super-admin')
            @can('view-billing')
                <section class="rounded-xl border border-slate-200 bg-white p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-slate-800">Billing Statement</h3>
                            <p class="mt-1 text-xs text-slate-500">Dispensed medications only are included. Cashier settlement is recorded through the billing API.</p>
                        </div>
                        @role('super-admin')
                            <form method="POST" action="{{ route('billing.encounters.generate', $encounter) }}">
                                @csrf
                                <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Generate / refresh statement</button>
                            </form>
                        @endrole
                    </div>
                    @if ($billingError)
                        <p role="alert" class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{{ $billingError }}</p>
                    @elseif ($billingSummary)
                        <div class="mt-4 flex flex-wrap justify-between gap-2 text-sm">
                            <span>Statement: {{ $billingSummary['statement_number'] ?? 'Not generated' }}</span>
                            <span>Status: {{ $billingSummary['status'] }}{{ ! $billingSummary['is_current'] && $billingSummary['statement_number'] ? ' (out of date)' : '' }}</span>
                            <strong>Total: {{ $billingSummary['currency'] }} {{ number_format((float) $billingSummary['total'], 2) }}</strong>
                        </div>
                        @if ($billingSummary['items'])
                            <div class="mt-4 divide-y divide-slate-100">
                                @foreach ($billingSummary['items'] as $item)
                                    <div class="flex justify-between gap-3 py-2 text-sm"><span>{{ $item['description'] }} × {{ $item['quantity'] }}</span><span>{{ $item['currency'] }} {{ number_format((float) $item['line_total'], 2) }}</span></div>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </section>
            @endcan
            @endrole

            @if ($inpatientAdmission && $inpatientAdmission->attending_provider?->user_id === auth()->id() && auth()->user()->hasRole('doctor'))
                <section class="rounded-xl border border-slate-200 bg-white p-6">
                    <h3 class="font-semibold text-slate-800">Inpatient Discharge Summary</h3>
                    @if ($inpatientAdmission->dischargeSummary?->status === \App\Models\DischargeSummary::STATUS_APPROVED)
                        <p class="mt-2 text-sm text-emerald-700">Approved by the assigned attending physician.</p>
                    @else
                        <form method="POST" action="{{ route('admissions.discharge-summary.store', $inpatientAdmission) }}" class="mt-4 space-y-3">
                            @csrf
                            <label class="block text-sm">Final diagnosis<textarea name="final_diagnosis" required maxlength="10000" rows="2" class="mt-1 w-full rounded border-slate-300">{{ old('final_diagnosis', $inpatientAdmission->dischargeSummary?->final_diagnosis) }}</textarea></label>
                            <label class="block text-sm">Clinical course<textarea name="clinical_course" required maxlength="30000" rows="3" class="mt-1 w-full rounded border-slate-300">{{ old('clinical_course', $inpatientAdmission->dischargeSummary?->clinical_course) }}</textarea></label>
                            <label class="block text-sm">Discharge instructions<textarea name="discharge_instructions" required maxlength="30000" rows="3" class="mt-1 w-full rounded border-slate-300">{{ old('discharge_instructions', $inpatientAdmission->dischargeSummary?->discharge_instructions) }}</textarea></label>
                            <label class="block text-sm">Follow-up plan<textarea name="follow_up_plan" maxlength="10000" rows="2" class="mt-1 w-full rounded border-slate-300">{{ old('follow_up_plan', $inpatientAdmission->dischargeSummary?->follow_up_plan) }}</textarea></label>
                            <div class="flex gap-2">
                                <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Save draft</button>
                            </form>
                            @if ($inpatientAdmission->dischargeSummary)
                                <form method="POST" action="{{ route('admissions.discharge-summary.approve', $inpatientAdmission) }}">
                                    @csrf
                                    <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Approve summary</button>
                                </form>
                            @endif
                            </div>
                    @endif
                </section>
            @endif
        @endif
    </div>

    @can('update', $encounter)
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-800 mb-3">Issue Prescription</h3>
            <form method="POST" action="{{ route('encounters.prescriptions.store', $encounter) }}" class="space-y-4">
                @csrf
                @if ($errors->any())
                    <div role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                        Please correct the prescription details and try again.
                    </div>
                @endif
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="prescription-medication" class="block text-sm font-medium text-slate-700 mb-1">Medication *</label>
                            <input id="prescription-medication" name="medication_name" value="{{ old('medication_name') }}" required maxlength="255" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                    </div>
                    <div>
                        <label for="prescription-dosage" class="block text-sm font-medium text-slate-700 mb-1">Dosage *</label>
                            <input id="prescription-dosage" name="dosage" value="{{ old('dosage') }}" required maxlength="255" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                    </div>
                </div>
                <div>
                    <label for="prescription-instructions" class="block text-sm font-medium text-slate-700 mb-1">Instructions *</label>
                        <textarea id="prescription-instructions" name="instructions" required maxlength="5000" rows="3" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">{{ old('instructions') }}</textarea>
                </div>
                <button type="submit" class="px-6 py-2.5 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Save Prescription</button>
            </form>
        </div>
    @endcan

    @can('complete', $encounter)
    @if ($encounter->status === 'OPEN')
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <h3 class="font-semibold text-slate-800">Disposition: Request Admission</h3>
        @if ($encounter->admission)
            <p class="mt-2 text-sm text-emerald-700">Admission request {{ $encounter->admission->admission_number }} is {{ strtolower($encounter->admission->status) }} and linked to Bed Management.</p>
        @else
            <form method="POST" action="{{ route('encounters.admission-request', $encounter) }}" class="mt-3 space-y-3">
                @csrf
                @if ($errors->any())
                    <div role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{{ $errors->first() }}</div>
                @endif
                <label class="block text-sm font-medium text-slate-700">Clinical reason for admission
                    <textarea name="reason" required maxlength="5000" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('reason') }}</textarea>
                </label>
                <button type="submit" class="rounded-lg bg-amber-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-amber-700">Request Inpatient Admission</button>
            </form>
        @endif
    </div>
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <h3 class="font-semibold text-slate-800 mb-3">Complete Encounter</h3>
        <form id="encounter-completion-form" method="POST" action="{{ route('encounters.complete', $encounter) }}" class="space-y-4">
            @csrf
            @if ($errors->any())
                <div role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">Please complete all required clinical documentation. {{ $errors->first() }}</div>
            @endif
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="block text-sm font-medium text-slate-700 mb-1">History of Present Illness *</label><textarea name="history_of_present_illness" required maxlength="30000" rows="3" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">{{ old('history_of_present_illness', $encounter->history_of_present_illness) }}</textarea></div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Physical Examination *</label><textarea name="physical_examination" required maxlength="30000" rows="3" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">{{ old('physical_examination', $encounter->physical_examination) }}</textarea></div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Primary Diagnosis — ICD-10 Code *</label><input name="primary_diagnosis_code" required maxlength="20" value="{{ old('primary_diagnosis_code', $encounter->primary_diagnosis_code) }}" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"></div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Primary Diagnosis Description *</label><input name="primary_diagnosis_description" required maxlength="255" value="{{ old('primary_diagnosis_description', $encounter->primary_diagnosis_description) }}" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"></div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Assessment *</label><textarea name="assessment" required maxlength="30000" rows="2" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">{{ old('assessment', $encounter->assessment) }}</textarea></div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Plan *</label><textarea name="plan" required maxlength="30000" rows="2" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">{{ old('plan', $encounter->plan) }}</textarea></div>
            </div>
            <div><label class="block text-sm font-medium text-slate-700 mb-1">Follow-up Date</label><input type="date" name="follow_up_date" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg md:w-64"></div>
            <button type="submit" class="px-6 py-2.5 text-sm bg-green-600 text-white rounded-lg">Complete</button>
        </form>
    </div>
    @endif
    @endcan
</div>
@endsection
