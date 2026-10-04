@extends('layouts.hims')

@section('title', 'Telehealth Intake')
@section('page-title', 'Pre-consultation Intake')
@section('page-kicker', 'Patient portal')
@section('page-description', 'Share information with your doctor before your telehealth appointment.')

@section('content')
<div class="mx-auto max-w-3xl space-y-5">
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        The AI urgency result is decision support only and is not a diagnosis. Your doctor reviews the information and makes clinical decisions.
    </div>

    @if ($session->intake?->is_emergency)
        <div role="alert" class="rounded-xl border border-red-300 bg-red-50 p-5 text-sm text-red-900">
            <h2 class="font-semibold">Emergency assessment recommended</h2>
            <p class="mt-1">Do not wait for a virtual appointment. Seek emergency care now or call 911 if you may be in immediate danger.</p>
        </div>
    @endif

    @unless ($aiConsentGranted)
        <div role="alert" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            Current AI-assisted care consent is required before submitting this intake.
            <a href="{{ route('patient-consents.portal', ['required' => 'AI_ASSISTED']) }}" class="font-semibold underline">Review and record consent</a>
        </div>
    @endunless

    <form method="POST" action="{{ route('patients.portal.telehealth.intake.store', $session) }}" class="space-y-5 rounded-xl border border-slate-200 bg-white p-6">
        @csrf
        <div>
            <label for="symptoms" class="mb-1 block text-sm font-medium text-slate-700">Symptoms</label>
            <textarea id="symptoms" name="symptoms" rows="4" required maxlength="2000" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('symptoms', $session->intake?->symptoms) }}</textarea>
            @error('symptoms')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label for="duration" class="mb-1 block text-sm font-medium text-slate-700">How long have you had these symptoms?</label>
                <input id="duration" name="duration" value="{{ old('duration', $session->intake?->duration) }}" required maxlength="100" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('duration')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="severity" class="mb-1 block text-sm font-medium text-slate-700">Severity (1–10)</label>
                <input id="severity" name="severity" type="number" min="1" max="10" value="{{ old('severity', $session->intake?->severity) }}" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('severity')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="allergies" class="mb-1 block text-sm font-medium text-slate-700">Allergies</label>
                <textarea id="allergies" name="allergies" rows="3" maxlength="2000" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('allergies', $session->intake?->allergies) }}</textarea>
                @error('allergies')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="current_medications" class="mb-1 block text-sm font-medium text-slate-700">Current medications</label>
                <textarea id="current_medications" name="current_medications" rows="3" maxlength="2000" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('current_medications', $session->intake?->current_medications) }}</textarea>
                @error('current_medications')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
        </div>

        <fieldset class="rounded-lg border border-slate-200 p-4">
            <legend class="px-1 text-sm font-medium text-slate-700">Optional vital signs</legend>
            <div class="grid gap-3 md:grid-cols-3">
                @foreach (['gcs' => 'GCS (3–15)', 'spo2' => 'Oxygen saturation (%)', 'blood_pressure' => 'Systolic BP (mmHg)', 'heart_rate' => 'Heart rate (bpm)', 'respiratory_rate' => 'Respiratory rate (/min)', 'temperature' => 'Temperature (°C)'] as $key => $label)
                    <div>
                        <label for="vitals_{{ $key }}" class="mb-1 block text-xs font-medium text-slate-600">{{ $label }}</label>
                        <input id="vitals_{{ $key }}" name="vitals[{{ $key }}]" type="number" step="{{ $key === 'temperature' || $key === 'spo2' || $key === 'blood_pressure' ? '0.1' : '1' }}" value="{{ old('vitals.'.$key, $session->intake?->vitals[$key] ?? '') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('vitals.'.$key)<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
        </fieldset>

        <div class="flex items-center justify-between gap-3">
            <a href="{{ route('patients.portal.telehealth') }}" class="text-sm font-medium text-slate-600 hover:underline">Back to telehealth</a>
            <button type="submit" @disabled(!$aiConsentGranted) class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Save intake</button>
        </div>
    </form>
</div>
@endsection
