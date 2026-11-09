@extends('layouts.hims')

@section('title', 'AI Symptom Checker')

@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-800">Pre-booking AI Symptom Checker</h1>
        <p class="mt-1 text-sm text-slate-600">Share the current symptoms and optional vital signs to receive a care-routing suggestion.</p>
    </div>
    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">
        If an external AI provider is enabled, your symptom description is sent to that provider for triage support. The system masks names, contact details, reference codes, and obvious identifiers first; it sends age range and sex only when configured. The application saves a triage record, including the submitted symptoms, for care continuity. The external provider may process the request under its own terms.
    </div>

    @if ($aiConsentGranted === false && $selectedPatient)
        @php
            $consentUrl = auth()->user()->hasRole('patient')
                ? route('patient-consents.portal', ['required' => 'AI_ASSISTED'])
                : route('patients.consents.index', ['patient' => $selectedPatient, 'required' => 'AI_ASSISTED']);
        @endphp
        <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            AI-assisted care consent is required before running this checker.
            <a href="{{ $consentUrl }}" class="font-semibold underline">Review and record consent</a>
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
            <p class="font-semibold">Please review the form:</p>
            <ul class="mt-2 list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('symptom-checker.store') }}" class="space-y-5 rounded-xl border border-slate-200 bg-white p-6">
        @csrf
        @if (auth()->user()->hasRole('patient'))
            <input type="hidden" name="patient_id" value="{{ $patients->first()->id }}">
            <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">Patient: {{ $patients->first()->full_name }} — {{ $patients->first()->mrn }}</p>
        @else
            <div>
                <label for="patient_id" class="mb-1 block text-sm font-medium text-slate-700">Patient <span class="hims-required" aria-hidden="true">*</span></label>
                <select id="patient_id" name="patient_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Select patient</option>
                    @foreach ($patients as $patient)
                        <option value="{{ $patient->id }}" @selected(old('patient_id') == $patient->id)>{{ $patient->full_name }} — {{ $patient->mrn }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div>
            <label for="age" class="mb-1 block text-sm font-medium text-slate-700">Age (years) <span class="hims-required" aria-hidden="true">*</span></label>
            <input id="age" name="age" type="number" min="0" max="120" required value="{{ old('age') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div>
            <label for="symptoms" class="mb-1 block text-sm font-medium text-slate-700">Symptoms <span class="hims-required" aria-hidden="true">*</span></label>
            <textarea id="symptoms" name="symptoms" rows="4" required minlength="3" maxlength="2000" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Describe symptoms, separated by commas or new lines">{{ old('symptoms') }}</textarea>
        </div>

        <fieldset class="space-y-3">
            <legend class="text-sm font-semibold text-slate-700">Optional vital signs</legend>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div><label for="gcs" class="mb-1 block text-xs text-slate-600">GCS (3–15)</label><input id="gcs" name="vitals[gcs]" type="number" min="3" max="15" value="{{ old('vitals.gcs') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
                <div><label for="spo2" class="mb-1 block text-xs text-slate-600">O₂ saturation (%)</label><input id="spo2" name="vitals[spo2]" type="number" min="0" max="100" step="0.1" value="{{ old('vitals.spo2') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
                <div><label for="blood_pressure" class="mb-1 block text-xs text-slate-600">Systolic BP (mmHg)</label><input id="blood_pressure" name="vitals[blood_pressure]" type="number" min="1" max="300" value="{{ old('vitals.blood_pressure') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
                <div><label for="heart_rate" class="mb-1 block text-xs text-slate-600">Heart rate (bpm)</label><input id="heart_rate" name="vitals[heart_rate]" type="number" min="1" max="300" value="{{ old('vitals.heart_rate') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
                <div><label for="respiratory_rate" class="mb-1 block text-xs text-slate-600">Respiratory rate (/min)</label><input id="respiratory_rate" name="vitals[respiratory_rate]" type="number" min="1" max="100" value="{{ old('vitals.respiratory_rate') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
                <div><label for="temperature" class="mb-1 block text-xs text-slate-600">Temperature (°C)</label><input id="temperature" name="vitals[temperature]" type="number" min="25" max="45" step="0.1" value="{{ old('vitals.temperature') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            </div>
        </fieldset>

        <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">This AI tool provides decision support only and is not a diagnosis. For a medical emergency, seek emergency care immediately.</p>
        <button type="submit" @disabled($aiConsentGranted === false) class="rounded-lg bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50">Check symptoms</button>
    </form>
</div>
@endsection
