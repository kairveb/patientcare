@extends('layouts.hims')

@section('title', 'Outpatient Encounters')
@section('page-kicker', 'Care Delivery')
@section('page-title', 'Outpatient Encounters')
@section('page-badge', 'TOCS')

@section('content')
<div class="space-y-6">
    <div class="panel-card overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">Encounter list</h2>
                <p class="text-sm text-slate-600">Telehealth and Outpatient Care System (TOCS)</p>
            </div>
            <div class="flex items-center gap-2">
                @if (request()->hasAny(['q', 'type', 'status']))
                    <a href="{{ route('outpatient.index') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Clear filters</a>
                @endif
                @role('doctor')
                    @can('create-encounters')
                        @if (auth()->user()->provider)
                            <button type="button" class="rounded-2xl bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700" data-bs-toggle="modal" data-bs-target="#encounterModal">New Encounter</button>
                        @endif
                    @endcan
                @endrole
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr>
                        <th class="text-left align-top">Number</th>
                        <th class="relative text-left align-top">
                            <div class="flex items-center gap-2">
                                <span>Patient</span>
                                <button type="button" data-filter-trigger data-filter-target="encounter-patient-filter" aria-expanded="false" class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:text-slate-700" aria-label="Filter patient">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5">
                                        <path fill-rule="evenodd" d="M2.75 4.5A.75.75 0 0 1 3.5 3.75h13a.75.75 0 0 1 0 1.5h-13a.75.75 0 0 1-.75-.75Zm2.5 5.25a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5a.75.75 0 0 1-.75-.75Zm2.5 5.25a.75.75 0 0 1 .75-.75h2.5a.75.75 0 0 1 0 1.5h-2.5a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                            <div id="encounter-patient-filter" class="filter-panel hidden absolute left-0 top-full z-20 mt-2 w-72 rounded-2xl border border-slate-200 bg-white p-3 shadow-xl">
                                <form method="GET" class="space-y-3">
                                    <input type="hidden" name="type" value="{{ request('type') }}">
                                    <input type="hidden" name="status" value="{{ request('status') }}">
                                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Search patient name..." class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 placeholder:text-slate-400 focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    <div class="flex items-center justify-end gap-2 pt-1">
                                        <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">Apply</button>
                                    </div>
                                </form>
                            </div>
                        </th>
                        <th class="text-left align-top">Provider</th>
                        <th class="relative text-left align-top">
                            <div class="flex items-center gap-2">
                                <span>Date/Time</span>
                            </div>
                        </th>
                        <th class="relative text-left align-top">
                            <div class="flex items-center gap-2">
                                <span>Type</span>
                                <button type="button" data-filter-trigger data-filter-target="encounter-type-filter" aria-expanded="false" class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:text-slate-700" aria-label="Filter encounter type">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5">
                                        <path fill-rule="evenodd" d="M2.75 4.5A.75.75 0 0 1 3.5 3.75h13a.75.75 0 0 1 0 1.5h-13a.75.75 0 0 1-.75-.75Zm2.5 5.25a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5a.75.75 0 0 1-.75-.75Zm2.5 5.25a.75.75 0 0 1 .75-.75h2.5a.75.75 0 0 1 0 1.5h-2.5a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                            <div id="encounter-type-filter" class="filter-panel hidden absolute left-0 top-full z-20 mt-2 w-64 rounded-2xl border border-slate-200 bg-white p-3 shadow-xl">
                                <form method="GET" class="space-y-3">
                                    <input type="hidden" name="q" value="{{ request('q') }}">
                                    <input type="hidden" name="status" value="{{ request('status') }}">
                                    <select name="type" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                        <option value="">All encounter types</option>
                                        <option value="OUTPATIENT" {{ request('type') === 'OUTPATIENT' ? 'selected' : '' }}>Outpatient</option>
                                        <option value="TELEHEALTH" {{ request('type') === 'TELEHEALTH' ? 'selected' : '' }}>Telehealth</option>
                                        <option value="EMERGENCY" {{ request('type') === 'EMERGENCY' ? 'selected' : '' }}>Emergency</option>
                                    </select>
                                    <div class="flex items-center justify-end gap-2 pt-1">
                                        <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">Apply</button>
                                    </div>
                                </form>
                            </div>
                        </th>
                        <th class="relative text-left align-top">
                            <div class="flex items-center gap-2">
                                <span>Status</span>
                                <button type="button" data-filter-trigger data-filter-target="encounter-status-filter" aria-expanded="false" class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:text-slate-700" aria-label="Filter status">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5">
                                        <path fill-rule="evenodd" d="M2.75 4.5A.75.75 0 0 1 3.5 3.75h13a.75.75 0 0 1 0 1.5h-13a.75.75 0 0 1-.75-.75Zm2.5 5.25a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5a.75.75 0 0 1-.75-.75Zm2.5 5.25a.75.75 0 0 1 .75-.75h2.5a.75.75 0 0 1 0 1.5h-2.5a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                            <div id="encounter-status-filter" class="filter-panel hidden absolute left-0 top-full z-20 mt-2 w-64 rounded-2xl border border-slate-200 bg-white p-3 shadow-xl">
                                <form method="GET" class="space-y-3">
                                    <input type="hidden" name="q" value="{{ request('q') }}">
                                    <input type="hidden" name="type" value="{{ request('type') }}">
                                    <select name="status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                        <option value="">All statuses</option>
                                        <option value="OPEN" {{ request('status') === 'OPEN' ? 'selected' : '' }}>Open</option>
                                        <option value="COMPLETED" {{ request('status') === 'COMPLETED' ? 'selected' : '' }}>Completed</option>
                                    </select>
                                    <div class="flex items-center justify-end gap-2 pt-1">
                                        <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">Apply</button>
                                    </div>
                                </form>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($encounters as $enc)
                        <tr class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="py-3 px-4 font-mono text-xs">{{ $enc->encounter_number }}</td>
                            <td class="py-3 px-4 font-medium"><a href="{{ route('patients.show', $enc->patient) }}" class="text-teal-600 hover:underline">{{ $enc->patient->full_name ?? '—' }}</a></td>
                            <td class="py-3 px-4">{{ $enc->provider->full_name ?? '—' }}</td>
                            <td class="py-3 px-4">{{ $enc->started_at?->format('M d, Y g:i A') ?? '—' }}</td>
                            <td class="py-3 px-4">{{ $enc->type }}</td>
                            <td class="py-3 px-4"><span class="px-2 py-1 text-xs rounded-full bg-slate-100">{{ $enc->status }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-slate-400">No encounters found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-slate-200">{{ $encounters->links() }}</div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        return;
        const triggers = document.querySelectorAll('[data-filter-trigger]');
        const panels = document.querySelectorAll('.filter-panel');

        const closePanels = () => {
            panels.forEach((panel) => panel.classList.add('hidden'));
            triggers.forEach((trigger) => trigger.setAttribute('aria-expanded', 'false'));
        };

        triggers.forEach((trigger) => {
            trigger.addEventListener('click', function (event) {
                event.stopPropagation();
                const targetId = trigger.getAttribute('data-filter-target');
                const panel = document.getElementById(targetId);
                const isOpen = !panel.classList.contains('hidden');

                closePanels();

                if (!isOpen) {
                    panel.classList.remove('hidden');
                    trigger.setAttribute('aria-expanded', 'true');
                }
            });
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('[data-filter-trigger]') && !event.target.closest('.filter-panel')) {
                closePanels();
            }
        });
    });
</script>

@role('doctor')
@can('create-encounters')
@if (auth()->user()->provider)
<div class="modal fade" id="encounterModal" tabindex="-1" aria-labelledby="encounterModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content border-0 shadow-2xl">
            <div class="modal-header border-b border-slate-200 px-5 py-4">
                <div>
                    <h5 class="modal-title text-lg font-semibold text-slate-900" id="encounterModalLabel">New Clinical Encounter</h5>
                    <p class="mt-1 text-sm text-slate-500">Telehealth and Outpatient Care System (TOCS)</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body px-5 py-5">
                <form method="POST" action="{{ route('encounters.store') }}" class="space-y-5">
                    @csrf
                    @if ($errors->any())
                        <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{{ $errors->first() }}</div>
                    @endif

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Patient *</label>
                            <select name="patient_id" required class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                <option value="">Select patient</option>
                                @foreach ($patientOptions as $patient)
                                    <option value="{{ $patient->id }}" @selected((string) old('patient_id') === (string) $patient->id)>{{ $patient->full_name }} — {{ $patient->mrn }}</option>
                                @endforeach
                            </select>
                            @error('patient_id') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Provider *</label>
                            <select name="provider_id" required class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                <option value="">Select provider</option>
                                @foreach ($providers as $provider)
                                    <option value="{{ $provider->id }}" @selected((string) old('provider_id', auth()->user()->provider->id) === (string) $provider->id)>{{ $provider->full_name }}</option>
                                @endforeach
                            </select>
                            @error('provider_id') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Appointment (optional)</label>
                            <select name="appointment_id" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                <option value="">None</option>
                                @foreach ($appointments as $apt)
                                    <option value="{{ $apt->id }}" @selected((string) old('appointment_id') === (string) $apt->id)>{{ $apt->appointment_number }} — {{ $apt->patient->full_name ?? '' }}</option>
                                @endforeach
                            </select>
                            @error('appointment_id') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Encounter Type *</label>
                            <select name="type" required class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                <option value="OUTPATIENT" @selected(old('type', 'OUTPATIENT') === 'OUTPATIENT')>Outpatient</option>
                                <option value="TELEHEALTH" @selected(old('type') === 'TELEHEALTH')>Telehealth</option>
                            </select>
                            @error('type') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Chief Complaint</label>
                        <textarea name="chief_complaint" rows="2" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">{{ old('chief_complaint') }}</textarea>
                        @error('chief_complaint') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">History of Present Illness</label>
                            <textarea name="history_of_present_illness" rows="3" maxlength="30000" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">{{ old('history_of_present_illness') }}</textarea>
                            @error('history_of_present_illness') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Physical Examination</label>
                            <textarea name="physical_examination" rows="3" maxlength="30000" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">{{ old('physical_examination') }}</textarea>
                            @error('physical_examination') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Primary Diagnosis — ICD-10 Code</label>
                            <input name="primary_diagnosis_code" maxlength="20" value="{{ old('primary_diagnosis_code') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                            @error('primary_diagnosis_code') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Primary Diagnosis Description</label>
                            <input name="primary_diagnosis_description" maxlength="255" value="{{ old('primary_diagnosis_description') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                            @error('primary_diagnosis_description') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <h4 class="mb-3 text-sm font-semibold text-slate-800">Vital Signs</h4>
                        <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
                            <div>
                                <label class="mb-1 block text-xs text-slate-500">BP</label>
                                <input name="vitals[blood_pressure]" placeholder="120/80" value="{{ old('vitals.blood_pressure') }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                @error('vitals.blood_pressure') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-slate-500">Heart Rate</label>
                                <input type="number" name="vitals[heart_rate]" min="1" max="250" value="{{ old('vitals.heart_rate') }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                @error('vitals.heart_rate') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-slate-500">Resp. Rate</label>
                                <input type="number" name="vitals[respiratory_rate]" min="1" max="80" value="{{ old('vitals.respiratory_rate') }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                @error('vitals.respiratory_rate') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-slate-500">Temp (°C)</label>
                                <input type="number" step="0.1" min="25" max="45" name="vitals[temperature]" value="{{ old('vitals.temperature') }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                @error('vitals.temperature') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-slate-500">SpO2</label>
                                <input type="number" step="0.1" min="0" max="100" name="vitals[spo2]" value="{{ old('vitals.spo2') }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                @error('vitals.spo2') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-4">
                            <label class="text-xs text-slate-500">Height (cm)
                                <input type="number" name="vitals[height_cm]" min="55" max="250" step="0.1" value="{{ old('vitals.height_cm') }}" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm">
                                @error('vitals.height_cm') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                            </label>
                            <label class="text-xs text-slate-500">Weight (kg)
                                <input type="number" name="vitals[weight]" min="1" max="300" step="0.1" value="{{ old('vitals.weight') }}" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm">
                                @error('vitals.weight') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Clinical Notes</label>
                        <textarea name="notes" rows="3" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">{{ old('notes') }}</textarea>
                        @error('notes') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Assessment</label>
                            <textarea name="assessment" rows="2" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">{{ old('assessment') }}</textarea>
                            @error('assessment') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Plan</label>
                            <textarea name="plan" rows="2" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">{{ old('plan') }}</textarea>
                            @error('plan') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Follow-up Date</label>
                        <input type="date" name="follow_up_date" value="{{ old('follow_up_date') }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        @error('follow_up_date') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-col gap-3 pt-2 sm:flex-row sm:justify-end">
                        <button type="button" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-100" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700">Save Encounter</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endif
@endcan
@endrole
@if ($errors->hasAny(['patient_id', 'provider_id', 'appointment_id', 'type', 'chief_complaint', 'history_of_present_illness', 'physical_examination', 'primary_diagnosis_code', 'primary_diagnosis_description', 'vitals', 'vitals.height_cm', 'vitals.weight', 'vitals.blood_pressure', 'vitals.heart_rate', 'vitals.respiratory_rate', 'vitals.temperature', 'vitals.spo2', 'assessment', 'plan', 'notes', 'follow_up_date']))
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById('encounterModal');
            if (modal && window.bootstrap) {
                window.bootstrap.Modal.getOrCreateInstance(modal).show();
            }
        });
    </script>
@endif
@endsection
