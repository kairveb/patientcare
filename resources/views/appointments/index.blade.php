@extends('layouts.hims')

@section('title', 'Appointments')
@section('page-kicker', 'Scheduling')
@section('page-title', 'Appointments')
@section('page-badge', 'ASS')

@section('content')
<div class="space-y-6">
    <div class="panel-card overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">Appointment schedule</h2>
                <p class="text-sm text-slate-600">Appointment and Scheduling System (ASS)</p>
            </div>
            <div class="flex items-center gap-2">
                @if (auth()->user()->hasRole('patient'))
                    <a href="{{ route('doctors.index') }}" class="rounded-2xl border border-teal-600 px-4 py-2 text-sm font-semibold text-teal-700 hover:bg-teal-50">Find a doctor</a>
                @endif
                @if (auth()->user()->hasAnyRole(['registration', 'nurse', 'admin', 'super-admin']))
                    <a href="{{ route('providers.profiles') }}" class="rounded-2xl border border-teal-600 px-4 py-2 text-sm font-semibold text-teal-700 hover:bg-teal-50">Doctor profiles</a>
                    <a href="{{ route('appointments.approvals') }}" class="rounded-2xl border border-sky-600 px-4 py-2 text-sm font-semibold text-sky-700 hover:bg-sky-50">Approval queue</a>
                @endif
                @if (request()->hasAny(['q', 'date', 'status', 'follow_up']))
                    <a href="{{ route('appointments.index') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Clear filters</a>
                @endif
                @role('registration', 'nurse', 'super-admin')
                    @if (auth()->user()->hasRole('registration', 'super-admin'))
                        <a href="{{ route('symptom-checker.create') }}" class="rounded-2xl border border-teal-600 px-4 py-2 text-sm font-semibold text-teal-700 hover:bg-teal-50">AI symptom checker</a>
                    @endif
                    <button type="button" class="rounded-2xl bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700" data-bs-toggle="modal" data-bs-target="#appointmentModal">Book Appointment</button>
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
                                <button type="button" data-filter-trigger data-filter-target="patient-filter" aria-expanded="false" class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:text-slate-700" aria-label="Filter patient">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5">
                                        <path fill-rule="evenodd" d="M2.75 4.5A.75.75 0 0 1 3.5 3.75h13a.75.75 0 0 1 0 1.5h-13a.75.75 0 0 1-.75-.75Zm2.5 5.25a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5a.75.75 0 0 1-.75-.75Zm2.5 5.25a.75.75 0 0 1 .75-.75h2.5a.75.75 0 0 1 0 1.5h-2.5a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                            <div id="patient-filter" class="filter-panel hidden absolute left-0 top-full z-20 mt-2 w-72 rounded-2xl border border-slate-200 bg-white p-3 shadow-xl">
                                <form method="GET" class="space-y-3">
                                    <input type="hidden" name="date" value="{{ request('date') }}">
                                    <input type="hidden" name="status" value="{{ request('status') }}">
                                    <select name="follow_up" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                        <option value="">All follow-up states</option>
                                        <option value="due" {{ request('follow_up') === 'due' ? 'selected' : '' }}>Follow-up due</option>
                                    </select>
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
                                <button type="button" data-filter-trigger data-filter-target="date-filter" aria-expanded="false" class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:text-slate-700" aria-label="Filter date">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5">
                                        <path fill-rule="evenodd" d="M2.75 4.5A.75.75 0 0 1 3.5 3.75h13a.75.75 0 0 1 0 1.5h-13a.75.75 0 0 1-.75-.75Zm2.5 5.25a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5a.75.75 0 0 1-.75-.75Zm2.5 5.25a.75.75 0 0 1 .75-.75h2.5a.75.75 0 0 1 0 1.5h-2.5a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                            <div id="date-filter" class="filter-panel hidden absolute left-0 top-full z-20 mt-2 w-64 rounded-2xl border border-slate-200 bg-white p-3 shadow-xl">
                                <form method="GET" class="space-y-3">
                                    <input type="hidden" name="q" value="{{ request('q') }}">
                                    <input type="hidden" name="status" value="{{ request('status') }}">
                                    <input type="hidden" name="follow_up" value="{{ request('follow_up') }}">
                                    <input type="date" name="date" value="{{ request('date') }}" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    <div class="flex items-center justify-end gap-2 pt-1">
                                        <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">Apply</button>
                                    </div>
                                </form>
                            </div>
                        </th>
                        <th class="text-left align-top">Type</th>
                        <th class="text-left align-top">Source</th>
                        <th class="relative text-left align-top">
                            <div class="flex items-center gap-2">
                                <span>Status</span>
                                <button type="button" data-filter-trigger data-filter-target="status-filter" aria-expanded="false" class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:text-slate-700" aria-label="Filter status">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5">
                                        <path fill-rule="evenodd" d="M2.75 4.5A.75.75 0 0 1 3.5 3.75h13a.75.75 0 0 1 0 1.5h-13a.75.75 0 0 1-.75-.75Zm2.5 5.25a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5a.75.75 0 0 1-.75-.75Zm2.5 5.25a.75.75 0 0 1 .75-.75h2.5a.75.75 0 0 1 0 1.5h-2.5a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                            <div id="status-filter" class="filter-panel hidden absolute left-0 top-full z-20 mt-2 w-64 rounded-2xl border border-slate-200 bg-white p-3 shadow-xl">
                                <form method="GET" class="space-y-3">
                                    <input type="hidden" name="q" value="{{ request('q') }}">
                                    <input type="hidden" name="date" value="{{ request('date') }}">
                                    <input type="hidden" name="follow_up" value="{{ request('follow_up') }}">
                                    <select name="status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                        <option value="">All statuses</option>
                                        @foreach ($statuses as $status)
                                            <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ $status }}</option>
                                        @endforeach
                                    </select>
                                    <div class="flex items-center justify-end gap-2 pt-1">
                                        <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">Apply</button>
                                    </div>
                                </form>
                            </div>
                        </th>
                        <th class="text-left align-top"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($appointments as $apt)
                        <tr>
                            <td class="font-mono text-xs">{{ $apt->appointment_number }}</td>
                            <td class="font-medium text-slate-900">
                                <div>{{ $apt->patient->full_name ?? '—' }}</div>
                                @if ($apt->encounter?->follow_up_date)
                                    <div class="mt-1 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800">Follow-up: {{ $apt->encounter->follow_up_date->format('M d, Y') }}</div>
                                @endif
                            </td>
                            <td>{{ $apt->provider->full_name ?? '—' }}</td>
                            <td>{{ $apt->starts_at->format('M d, Y g:i A') }}</td>
                            <td>{{ $apt->appointmentType->name ?? '—' }}</td>
                            <td class="text-xs">{{ str_replace('_', ' ', $apt->booking_source) }}</td>
                            <td>
                                @include('partials.status-badge', ['label' => $apt->status, 'variant' => App\Support\QueueStatus::appointmentVariant($apt->status)])
                            </td>
                            <td>
                                <button type="button" class="appointment-row-view-btn text-sm font-semibold text-teal-600 hover:text-teal-700" data-appointment-detail-url="{{ route('appointments.show', ['appointment' => $apt, 'modal' => 1]) }}">View</button>
                                @role('nurse')
                                    @if (in_array($apt->status, [\App\Models\Appointment::STATUS_CHECKED_IN, \App\Models\Appointment::STATUS_IN_CONSULTATION], true))
                                        <button type="button" class="ml-3 text-sm font-semibold text-indigo-600 hover:text-indigo-700" data-bs-toggle="modal" data-bs-target="#opdVitalsModal-{{ $apt->id }}">Record vitals</button>
                                    @endif
                                @endrole
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-10 text-center text-slate-500">No appointments scheduled yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 p-4">
            {{ $appointments->links() }}
        </div>
    </div>
</div>

@foreach ($appointments as $apt)
    <div class="modal fade" id="appointmentStatusModal-{{ $apt->id }}" tabindex="-1" aria-labelledby="appointmentStatusModalLabel-{{ $apt->id }}" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-2xl">
                <div class="modal-header border-b border-slate-200 px-5 py-4">
                    <div>
                        <h5 class="modal-title text-lg font-semibold text-slate-900" id="appointmentStatusModalLabel-{{ $apt->id }}">Appointment details</h5>
                        <p class="mt-1 text-sm text-slate-500">{{ $apt->appointment_number }} · {{ $apt->patient->full_name ?? '—' }}</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body px-5 py-5">
                    <div class="space-y-4 text-sm text-slate-700">
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div><span class="text-slate-500">Provider:</span> <span class="font-medium">{{ $apt->provider->full_name ?? '—' }}</span></div>
                            <div><span class="text-slate-500">Type:</span> <span class="font-medium">{{ $apt->appointmentType->name ?? '—' }}</span></div>
                            <div><span class="text-slate-500">Date/time:</span> <span class="font-medium">{{ $apt->starts_at->format('M d, Y g:i A') }}</span></div>
                            <div><span class="text-slate-500">Status:</span> <span class="ml-1">@include('partials.status-badge', ['label' => $apt->status, 'variant' => App\Support\QueueStatus::appointmentVariant($apt->status)])</span></div>
                        </div>
                        @if ($apt->reason)
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <div class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Reason</div>
                                <div class="mt-2 text-slate-700">{{ $apt->reason }}</div>
                            </div>
                        @endif
                    </div>
                    <div class="mt-5 flex justify-end gap-3">
                        <button type="button" class="appointment-open-details-btn inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-100" data-appointment-detail-url="{{ route('appointments.show', ['appointment' => $apt, 'modal' => 1]) }}">Open details</button>
                        <button type="button" class="inline-flex items-center justify-center rounded-xl bg-slate-800 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-900" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endforeach

<div class="modal fade" id="appointmentDetailModal" tabindex="-1" aria-labelledby="appointmentDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-2xl">
            <div class="modal-header border-b border-slate-200 px-5 py-4">
                <div>
                    <h5 class="modal-title text-lg font-semibold text-slate-900" id="appointmentDetailModalLabel">Appointment details</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-5 py-5" id="appointmentDetailModalBody">
                <div class="text-sm text-slate-500">Loading appointment details…</div>
            </div>
        </div>
    </div>
</div>

@role('nurse')
    @foreach ($appointments as $apt)
        @if (in_array($apt->status, [\App\Models\Appointment::STATUS_CHECKED_IN, \App\Models\Appointment::STATUS_IN_CONSULTATION], true))
            @php($intakeVitals = $apt->vitals)
            <div class="modal fade" id="opdVitalsModal-{{ $apt->id }}" tabindex="-1" aria-labelledby="opdVitalsModalLabel-{{ $apt->id }}" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content border-0 shadow-2xl">
                        <div class="modal-header border-b border-slate-200 px-5 py-4">
                            <div>
                                <h5 class="modal-title text-lg font-semibold text-slate-900" id="opdVitalsModalLabel-{{ $apt->id }}">OPD Nurse Intake</h5>
                                <p class="mt-1 text-sm text-slate-500">{{ $apt->appointment_number }} · {{ $apt->patient->full_name ?? 'Patient' }}</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body px-5 py-5">
                            <div class="opd-vitals-alert mb-4 hidden rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700" role="alert"></div>
                            <form class="opd-vitals-form space-y-4" method="POST" action="{{ route('appointments.vitals.store', $apt) }}">
                                @csrf
                                <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                                    <label class="text-sm text-slate-700">Height (cm) <span class="hims-required" aria-hidden="true">*</span>
                                        <input type="number" name="height_cm" min="55" max="250" step="0.1" required value="{{ $intakeVitals?->height_cm }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                    </label>
                                    <label class="text-sm text-slate-700">Weight (kg) <span class="hims-required" aria-hidden="true">*</span>
                                        <input type="number" name="weight" min="1" max="300" step="0.1" required value="{{ $intakeVitals?->weight }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                    </label>
                                    <label class="text-sm text-slate-700">BMI (calculated)
                                        <input type="text" class="opd-vitals-bmi mt-1 w-full rounded-lg border border-slate-200 bg-slate-100 px-3 py-2" value="{{ $intakeVitals?->bmi }}" readonly>
                                    </label>
                                    <label class="text-sm text-slate-700">Blood Pressure
                                        <input name="blood_pressure" maxlength="20" pattern="[0-9]{2,3}/[0-9]{2,3}" placeholder="120/80" value="{{ $intakeVitals?->blood_pressure }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                    </label>
                                    <label class="text-sm text-slate-700">Pulse (bpm)
                                        <input type="number" name="heart_rate" min="1" max="250" value="{{ $intakeVitals?->heart_rate }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                    </label>
                                    <label class="text-sm text-slate-700">Respiratory rate
                                        <input type="number" name="respiratory_rate" min="1" max="80" value="{{ $intakeVitals?->respiratory_rate }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                    </label>
                                    <label class="text-sm text-slate-700">Temperature (°C)
                                        <input type="number" name="temperature" min="25" max="45" step="0.1" value="{{ $intakeVitals?->temperature }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                    </label>
                                    <label class="text-sm text-slate-700">SpO2 (%)
                                        <input type="number" name="spo2" min="0" max="100" step="0.1" value="{{ $intakeVitals?->spo2 }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                    </label>
                                    <label class="text-sm text-slate-700">Pain score (0–10)
                                        <input type="number" name="pain_score" min="0" max="10" value="{{ $intakeVitals?->pain_score }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                    </label>
                                </div>
                                <p class="opd-vitals-bmi-error hidden text-sm text-rose-700">Enter a valid height and weight to calculate BMI.</p>
                                <div class="flex justify-end gap-3">
                                    <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" data-bs-dismiss="modal">Close</button>
                                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save intake vitals</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endforeach
@endrole

<script>
    document.addEventListener('DOMContentLoaded', function () {
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

        const detailModalEl = document.getElementById('appointmentDetailModal');
        const detailModalBody = document.getElementById('appointmentDetailModalBody');
        const detailModal = bootstrap.Modal.getOrCreateInstance(detailModalEl);

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const xsrfCookie = document.cookie
            .split('; ')
            .find((cookie) => cookie.startsWith('XSRF-TOKEN='));
        const xsrfToken = xsrfCookie ? decodeURIComponent(xsrfCookie.split('=')[1] || '') : '';

        const getFetchHeaders = (extra = {}) => ({
            Accept: 'text/html, application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            ...(xsrfToken ? { 'X-XSRF-TOKEN': xsrfToken } : {}),
            ...extra,
        });

        const refreshAppointmentsList = async () => {
            const listUrl = new URL(window.location.href);
            const response = await fetch(listUrl.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: getFetchHeaders(),
            });

            if (!response.ok) {
                return;
            }

            const html = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newTable = doc.querySelector('.panel-card table');
            const currentTable = document.querySelector('.panel-card table');
            if (newTable && currentTable) {
                currentTable.innerHTML = newTable.innerHTML;
            }

            const newPager = doc.querySelector('.panel-card .border-t');
            const currentPager = document.querySelector('.panel-card .border-t');
            if (newPager && currentPager) {
                currentPager.innerHTML = newPager.innerHTML;
            }
        };

        const loadAppointmentDetail = async (appointmentDetailUrl) => {
            detailModalBody.dataset.appointmentDetailUrl = appointmentDetailUrl;
            detailModalBody.innerHTML = '<div class="text-sm text-slate-500">Loading appointment details…</div>';
            detailModal.show();

            try {
                const response = await fetch(appointmentDetailUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: getFetchHeaders(),
                });

                if (!response.ok) {
                    throw new Error('Unable to load appointment details.');
                }

                const html = await response.text();
                detailModalBody.innerHTML = html;
                attachActionHandlers();
            } catch (error) {
                detailModalBody.innerHTML = `<div class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">${error.message}</div>`;
            }
        };

        const renderAlert = (message, type = 'error') => {
            const alertBox = detailModalBody.querySelector('#appointment-modal-alert');
            if (!alertBox) {
                return;
            }

            alertBox.textContent = message;
            alertBox.classList.remove('hidden', 'border-rose-200', 'bg-rose-50', 'text-rose-700', 'border-emerald-200', 'bg-emerald-50', 'text-emerald-700');
            alertBox.classList.add(type === 'success' ? 'border-emerald-200' : 'border-rose-200');
            alertBox.classList.add(type === 'success' ? 'bg-emerald-50' : 'bg-rose-50');
            alertBox.classList.add(type === 'success' ? 'text-emerald-700' : 'text-rose-700');
            alertBox.classList.remove('hidden');
        };

        const afterActionSuccess = async () => {
            await refreshAppointmentsList();
            const currentAppointmentUrl = detailModalBody.dataset.appointmentDetailUrl;
            if (currentAppointmentUrl) {
                await loadAppointmentDetail(currentAppointmentUrl);
            }
        };

        const attachActionHandlersToContainer = (container) => {
            const actionForms = container.querySelectorAll('.appointment-action-form');
            actionForms.forEach((form) => {
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();

                    const formData = new FormData(form);
                    const action = form.dataset.appointmentAction || 'update';
                    const alertBox = container.querySelector('#appointment-modal-alert');
                    if (alertBox) {
                        alertBox.classList.add('hidden');
                        alertBox.textContent = '';
                    }

                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: getFetchHeaders({
                                Accept: 'application/json, text/html',
                            }),
                            body: formData,
                        });

                        let payload = null;
                        const contentType = response.headers.get('content-type') || '';
                        if (contentType.includes('application/json')) {
                            payload = await response.json();
                        } else {
                            payload = { success: response.ok, message: response.ok ? 'Request completed.' : 'The request could not be completed.' };
                        }

                        if (!response.ok || payload?.success === false) {
                            const message = payload?.message || 'The request could not be completed.';
                            renderAlert(message, 'error');
                            return;
                        }

                        renderAlert(payload?.message || 'Updated successfully.', 'success');
                        const currentAppointmentUrl = container.dataset.appointmentDetailUrl;
                        if (currentAppointmentUrl) {
                            await afterActionSuccess();
                        }
                    } catch (error) {
                        renderAlert(error.message || 'The request could not be completed.', 'error');
                    }
                });
            });
        };

        const attachActionHandlers = () => attachActionHandlersToContainer(detailModalBody);

        const bookingModalEl = document.getElementById('appointmentModal');
        const bookingModalBody = bookingModalEl ? bookingModalEl.querySelector('.modal-body') : null;
        const bookingForm = document.getElementById('appointment-booking-form');

        document.querySelectorAll('.opd-vitals-form').forEach((form) => {
            const height = form.elements.height_cm;
            const weight = form.elements.weight;
            const bmiInput = form.querySelector('.opd-vitals-bmi');
            const bmiError = form.querySelector('.opd-vitals-bmi-error');
            const alertBox = form.closest('.modal-body').querySelector('.opd-vitals-alert');
            const updateBmi = () => {
                const heightCm = Number(height.value);
                const weightKg = Number(weight.value);
                const valid = heightCm >= 55 && heightCm <= 250 && weightKg >= 1 && weightKg <= 300;
                bmiInput.value = valid ? (weightKg / ((heightCm / 100) ** 2)).toFixed(2) : '';
                bmiError.classList.toggle('hidden', valid || (!height.value && !weight.value));
            };
            height.addEventListener('input', updateBmi);
            weight.addEventListener('input', updateBmi);
            updateBmi();

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                alertBox.classList.add('hidden');
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: getFetchHeaders({ Accept: 'application/json' }),
                        body: new FormData(form),
                    });
                    const payload = await response.json();
                    if (!response.ok || payload.success === false) {
                        const messages = Object.values(payload.errors || {}).flat();
                        alertBox.textContent = messages.join(' ') || payload.message || 'The intake vitals could not be saved.';
                        alertBox.classList.remove('hidden');
                        return;
                    }
                    bmiInput.value = payload.data?.bmi ?? bmiInput.value;
                    window.HimsComponents?.notify({ tone: 'success', message: payload.message || 'OPD intake vitals saved.' });
                    bootstrap.Modal.getOrCreateInstance(form.closest('.modal')).hide();
                    await refreshAppointmentsList();
                } catch (error) {
                    alertBox.textContent = error.message || 'The intake vitals could not be saved.';
                    alertBox.classList.remove('hidden');
                }
            });
        });

        const renderBookingAlert = (message, type = 'error') => {
            if (!bookingModalBody) {
                return;
            }

            const alertBox = bookingModalBody.querySelector('#booking-modal-alert');
            if (!alertBox) {
                return;
            }

            alertBox.textContent = message;
            alertBox.classList.remove('hidden', 'border-rose-200', 'bg-rose-50', 'text-rose-700', 'border-emerald-200', 'bg-emerald-50', 'text-emerald-700');
            alertBox.classList.add(type === 'success' ? 'border-emerald-200' : 'border-rose-200');
            alertBox.classList.add(type === 'success' ? 'bg-emerald-50' : 'bg-rose-50');
            alertBox.classList.add(type === 'success' ? 'text-emerald-700' : 'text-rose-700');
            alertBox.classList.remove('hidden');
        };

        if (bookingForm && bookingModalBody) {
            bookingForm.addEventListener('submit', async (event) => {
                event.preventDefault();

                const formData = new FormData(bookingForm);
                renderBookingAlert('', 'error');
                bookingModalBody.querySelector('#booking-modal-alert')?.classList.add('hidden');

                try {
                    const response = await fetch(bookingForm.action, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: getFetchHeaders({
                            Accept: 'application/json, text/html',
                        }),
                        body: formData,
                    });

                    let payload = null;
                    const contentType = response.headers.get('content-type') || '';
                    if (contentType.includes('application/json')) {
                        payload = await response.json();
                    } else {
                        payload = { success: response.ok, message: response.ok ? 'Request completed.' : 'The request could not be completed.' };
                    }

                    if (!response.ok || payload?.success === false) {
                        const message = payload?.message || 'The request could not be completed.';
                        renderBookingAlert(message, 'error');
                        return;
                    }

                    await refreshAppointmentsList();

                    bookingForm.reset();
                    const slotsContainer = bookingModalBody.querySelector('#slots-container');
                    if (slotsContainer) {
                        slotsContainer.innerHTML = '';
                    }
                    bookingModalBody.querySelector('[name="starts_at"]').value = '';
                    bookingModalBody.querySelector('#booking-modal-alert')?.classList.add('hidden');
                    bootstrap.Modal.getOrCreateInstance(bookingModalEl).hide();
                    window.HimsComponents?.notify({ tone: 'success', message: payload?.message || 'Appointment booked successfully.' });
                } catch (error) {
                    renderBookingAlert(error.message || 'The request could not be completed.', 'error');
                }
            });
        }

        document.addEventListener('click', function (event) {
            const rowViewButton = event.target.closest('.appointment-row-view-btn');
            if (rowViewButton) {
                event.preventDefault();
                const appointmentDetailUrl = rowViewButton.dataset.appointmentDetailUrl;
                if (appointmentDetailUrl) {
                    loadAppointmentDetail(appointmentDetailUrl);
                }
                return;
            }

            const detailsButton = event.target.closest('.appointment-open-details-btn');
            if (!detailsButton) {
                return;
            }

            const appointmentDetailUrl = detailsButton.dataset.appointmentDetailUrl;
            if (!appointmentDetailUrl) {
                return;
            }

            const summaryModal = detailsButton.closest('.modal');
            if (summaryModal) {
                const currentModal = bootstrap.Modal.getInstance(summaryModal);
                if (currentModal) {
                    currentModal.hide();
                }
            }

            loadAppointmentDetail(appointmentDetailUrl);
        });
    });
</script>

@role('registration', 'nurse', 'super-admin')
    <div class="modal fade" id="appointmentModal" tabindex="-1" aria-labelledby="appointmentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 bg-white shadow-2xl">
                <div class="modal-header border-b border-slate-200 bg-white px-5 py-4">
                    <div>
                        <h5 class="modal-title text-lg font-semibold text-slate-900" id="appointmentModalLabel">Book Appointment</h5>
                        <p class="mt-1 text-sm text-slate-500">Select provider, time slot, and visit details</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body bg-white px-5 py-5">
                    @include('appointments.partials.booking-form')
                </div>
            </div>
        </div>
    </div>
@endrole
@endsection
