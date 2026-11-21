@extends('layouts.hims')

@section('title', 'Dashboard · Patient Access & Care Coordination')
@section('page-kicker', 'Command Center')
@section('page-title', 'Dashboard')
@section('page-description', 'A modern operating view for patient access and care coordination.')
@section('page-badge', 'Live workspace')

@section('content')
@php
    $user = auth()->user();
    $userRoles = $user?->roles->pluck('name')->toArray() ?? [];
    $isRegistration = $user?->hasRole('registration') ?? false;
    $isNurse = $user?->hasRole('nurse') ?? false;
    $isDoctor = $user?->hasRole('doctor') ?? false;
    $isPatient = $user?->hasRole('patient') ?? false;
    $isAdmin = $user?->hasRole('super-admin') ?? false;
    $primaryRole = $isAdmin ? 'super-admin' : ($user?->roles()->first()?->name ?? 'guest');
    $userRole = $primaryRole;
@endphp

<div class="space-y-6">
    @if (($openReassignmentCount ?? 0) > 0)
        <a href="{{ route('appointment-reassignments.index') }}" class="flex items-center justify-between gap-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950">
            <span><strong>Provider coverage needs attention</strong><span class="block text-sm">Review pending or patient-declined doctor reassignment cases.</span></span>
            <span class="rounded-full bg-amber-200 px-3 py-1 text-lg font-bold">{{ $openReassignmentCount }}</span>
        </a>
    @endif
    @if (($pendingAppointmentApprovals ?? 0) > 0 && $user?->hasAnyRole(['registration', 'nurse', 'admin', 'super-admin']))
        <a href="{{ route('appointments.approvals') }}" class="flex items-center justify-between gap-4 rounded-xl border border-sky-300 bg-sky-50 p-4 text-sky-950">
            <span><strong>Appointment requests need review</strong><span class="block text-sm">Confirm or reject online appointment requests.</span></span>
            <span class="rounded-full bg-sky-200 px-3 py-1 text-lg font-bold">{{ $pendingAppointmentApprovals }}</span>
        </a>
    @endif
    @if (($patientReassignmentCount ?? 0) > 0)
        <a href="{{ route('patient.reassignments.index') }}" class="block rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950">
            <strong>You have an appointment update</strong>
            <span class="block text-sm">Review your doctor coverage options or waitlist status.</span>
        </a>
    @endif
    @if (isset($appointmentsTodayCount) || isset($pendingAppointmentApprovals) || isset($openReassignmentCount) || isset($openPrivacyRequestCount) || isset($averageTriageAgreement))
        <section class="space-y-3">
            <h2 class="text-lg font-semibold text-slate-900">Operational scorecards</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                @isset($appointmentsTodayCount)
                    <a href="{{ $isPatient ? route('patients.portal.appointments') : route('appointments.index') }}" class="panel-card p-4">
                        <div class="text-2xl font-bold text-slate-900">{{ $appointmentsTodayCount }}</div><div class="text-sm text-slate-600">Appointments today</div>
                    </a>
                @endisset
                @if (isset($pendingAppointmentApprovals) && $user?->hasAnyRole(['registration', 'nurse', 'admin', 'super-admin']))
                    <a href="{{ route('appointments.approvals') }}" class="panel-card p-4"><div class="text-2xl font-bold text-slate-900">{{ $pendingAppointmentApprovals }}</div><div class="text-sm text-slate-600">Pending approvals</div></a>
                @endif
                @isset($openReassignmentCount)
                    <a href="{{ route('appointment-reassignments.index') }}" class="panel-card p-4"><div class="text-2xl font-bold text-slate-900">{{ $openReassignmentCount }}</div><div class="text-sm text-slate-600">Open reassignment cases</div></a>
                @endisset
                @isset($openPrivacyRequestCount)
                    <a href="{{ route('privacy.requests.queue') }}" class="panel-card p-4"><div class="text-2xl font-bold text-slate-900">{{ $openPrivacyRequestCount }}</div><div class="text-sm text-slate-600">Open privacy requests</div></a>
                @endisset
                @if (array_key_exists('averageTriageAgreement', get_defined_vars()))
                    <a href="{{ route('reports.ai-evaluation') }}" class="panel-card p-4"><div class="text-2xl font-bold text-slate-900">{{ $averageTriageAgreement === null ? '—' : $averageTriageAgreement.'%' }}</div><div class="text-sm text-slate-600">Average triage agreement</div></a>
                @endif
            </div>
        </section>
    @endif
    @isset($appointmentChart)
        <section class="grid gap-4 xl:grid-cols-2">
            <div class="panel-card p-5">
                <h2 class="font-semibold text-slate-900">Appointments per week by status</h2>
                <div class="mt-3 overflow-x-auto">
                    <svg viewBox="0 0 620 190" role="img" aria-label="Weekly appointments stacked by status" class="h-48 min-w-[520px] w-full">
                        <line x1="20" y1="150" x2="610" y2="150" stroke="#cbd5e1" />
                        @php
                            $statusColors = [
                                'PENDING' => '#f59e0b', 'CONFIRMED' => '#0f766e', 'CHECKED-IN' => '#0284c7',
                                'IN-CONSULTATION' => '#7c3aed', 'COMPLETED' => '#16a34a', 'CANCELLED' => '#dc2626',
                                'NO-SHOW' => '#64748b', 'REJECTED' => '#9f1239',
                            ];
                            $chartMax = max(1, (int) $appointmentChart->map(fn ($counts) => $counts->sum())->max());
                        @endphp
                        @foreach ($appointmentChart as $week => $counts)
                            @php
                                $x = 35 + $loop->index * 70;
                                $y = 150;
                            @endphp
                            @foreach ($statusColors as $status => $color)
                                @php
                                    $barHeight = $counts[$status] / $chartMax * 120;
                                @endphp
                                @if ($barHeight > 0)
                                    @php
                                        $y -= $barHeight;
                                    @endphp
                                    <rect x="{{ $x }}" y="{{ $y }}" width="34" height="{{ $barHeight }}" fill="{{ $color }}" />
                                @endif
                            @endforeach
                            <text x="{{ $x + 17 }}" y="170" text-anchor="middle" font-size="9" fill="#475569">{{ substr($week, -3) }}</text>
                        @endforeach
                    </svg>
                </div>
                <div class="mt-2 flex flex-wrap gap-3 text-xs">
                    @foreach ($statusColors as $status => $color)<span class="inline-flex items-center gap-1"><i class="h-2.5 w-2.5 rounded-sm" style="background-color: {{ $color }}"></i>{{ $status }}</span>@endforeach
                </div>
            </div>
            <div class="space-y-4">
                <div class="panel-card p-5">
                    <h2 class="font-semibold text-slate-900">Triage urgency distribution</h2>
                    <div class="mt-3 space-y-2">
                        @php
                            $maxUrgencyCount = max(1, max($triageUrgencyChart));
                        @endphp
                        @foreach ($triageUrgencyChart as $level => $count)
                            <div class="grid grid-cols-[6rem_1fr_2rem] items-center gap-2 text-sm">
                                <span>{{ $level }}</span><div class="h-4 overflow-hidden rounded bg-slate-100"><div class="h-full bg-amber-500" style="width: {{ $count / $maxUrgencyCount * 100 }}%"></div></div><span class="text-right">{{ $count }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="panel-card p-5">
                    <h2 class="font-semibold text-slate-900">Virtual vs in-person appointments</h2>
                    @php
                        $maxConsultationCount = max(1, (int) $consultationTypeChart->max());
                    @endphp
                    <div class="mt-3 space-y-2">
                        @foreach (['Virtual', 'In-person'] as $kind)
                            @php
                                $count = (int) $consultationTypeChart->get($kind, 0);
                            @endphp
                            <div class="grid grid-cols-[6rem_1fr_2rem] items-center gap-2 text-sm">
                                <span>{{ $kind }}</span><div class="h-4 overflow-hidden rounded bg-slate-100"><div class="h-full bg-sky-600" style="width: {{ $count / $maxConsultationCount * 100 }}%"></div></div><span class="text-right">{{ $count }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>
    @endisset
    @if ($isNurse || $isDoctor || $isAdmin)
        <div data-emergency-alerts class="sr-only" aria-live="assertive"></div>
    @endif

    @if ($isPatient)
        @can('portal-dashboard')
            <div class="modal fade" id="preRegistrationModal" tabindex="-1" aria-labelledby="preRegistrationModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-xl">
                    <div class="modal-content border-0 shadow-2xl">
                        <div class="modal-header border-b border-slate-200 px-5 py-4">
                            <div>
                                <h5 class="modal-title text-lg font-semibold text-slate-900" id="preRegistrationModalLabel">Pre-registration</h5>
                                <p class="mt-1 text-sm text-slate-500">Secure arrival details before your visit</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body px-5 py-5">
                            <form method="POST" action="{{ route('portal.pre-register.store') }}" class="space-y-6">
                                @csrf

                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-700">
                                    This form is for your pre-arrival information only. Clinical or vital-sign fields are intentionally left blank and will be completed by staff during intake.
                                </div>

                                @include('portal.partials.pre-registration-fields', [
                                    'patient' => $user->patient,
                                    'address' => $user->patient?->primaryAddress(),
                                    'contact' => $user->patient?->emergencyContacts->first(),
                                ])

                                <div class="flex flex-col gap-3 pt-2 sm:flex-row sm:justify-end">
                                    <button type="button" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700">Save pre-registration</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @endcan
    @endif

    @if (! $isPatient)
        <div class="modal fade" id="registerPatientModal" tabindex="-1" aria-labelledby="registerPatientModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content border-0 shadow-2xl">
                    <div class="modal-header border-b border-slate-200 px-5 py-4">
                        <div>
                            <h5 class="modal-title text-lg font-semibold text-slate-900" id="registerPatientModalLabel">Register Patient</h5>
                            <p class="mt-1 text-sm text-slate-500">Patient demographics and contact info</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body px-5 py-5">
                        @include('patients.partials.registration-form')
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($userRole === 'super-admin')
        <section class="panel-card p-6 lg:p-8">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.3em] text-teal-600">Care coordination overview</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-900">Operational snapshot for today</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Live data from registration, scheduling, telehealth, and bed management.</p>
                </div>
                <div class="metric-pill">Live data • Updated now</div>
            </div>
        </section>

        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <a href="{{ route('patients.index') }}" class="panel-card h-full min-h-[220px] p-5 transition hover:-translate-y-1 hover:border-teal-400">
                <div class="flex items-center justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-50 text-teal-600"><i class="bi bi-person-plus-fill text-xl"></i></div>
                    <span class="status-pill success">Active</span>
                </div>
                <div class="mt-6 text-3xl font-semibold text-slate-900">{{ $todayPatients }}</div>
                <div class="mt-1 text-sm text-slate-600">Patients registered today</div>
            </a>

            <a href="{{ route('appointments.index') }}" class="panel-card h-full min-h-[220px] p-5 transition hover:-translate-y-1 hover:border-sky-400">
                <div class="flex items-center justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-50 text-sky-600"><i class="bi bi-calendar-check-fill text-xl"></i></div>
                    <span class="status-pill info">Booked</span>
                </div>
                <div class="mt-6 text-3xl font-semibold text-slate-900">{{ $todayAppointments }}</div>
                <div class="mt-1 text-sm text-slate-600">Appointments scheduled</div>
            </a>

            <a href="{{ route('telehealth.index') }}" class="panel-card h-full min-h-[220px] p-5 transition hover:-translate-y-1 hover:border-violet-400">
                <div class="flex items-center justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-50 text-violet-600"><i class="bi bi-camera-video-fill text-xl"></i></div>
                    <span class="status-pill warning">Live</span>
                </div>
                <div class="mt-6 text-3xl font-semibold text-slate-900">{{ $telehealthAppointments }}</div>
                <div class="mt-1 text-sm text-slate-600">Telehealth sessions</div>
            </a>

            @can('view-er')
            <a href="{{ route('emergency.index') }}" class="panel-card h-full min-h-[220px] p-5 transition hover:-translate-y-1 hover:border-rose-400">
                <div class="flex items-center justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-50 text-rose-600"><i class="bi bi-hospital text-xl"></i></div>
                    <span class="status-pill danger">Active</span>
                </div>
                <div class="mt-6 text-3xl font-semibold text-slate-900">{{ $erQueue->count() }}</div>
                <div class="mt-1 text-sm text-slate-600">Patients in ER queue</div>
            </a>
            @endcan

            @can('view-admissions')
            <a href="{{ route('admissions.index') }}" class="panel-card h-full min-h-[220px] p-5 transition hover:-translate-y-1 hover:border-violet-400">
                <div class="flex items-center justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-50 text-violet-600"><i class="bi bi-box-arrow-in-right text-xl"></i></div>
                    <span class="status-pill warning">Pending</span>
                </div>
                <div class="mt-6 text-3xl font-semibold text-slate-900">{{ ($pendingAdmissions ?? collect())->count() }}</div>
                <div class="mt-1 text-sm text-slate-600">Admission requests</div>
            </a>
            @endcan

            <a href="{{ route('inpatient.index') }}" class="panel-card h-full min-h-[220px] p-5 transition hover:-translate-y-1 hover:border-amber-400">
                <div class="flex items-center justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 text-amber-600"><i class="bi bi-hospital-fill text-xl"></i></div>
                    <span class="status-pill danger">In use</span>
                </div>
                <div class="mt-6 text-3xl font-semibold text-slate-900">{{ $occupiedBeds }}</div>
                <div class="mt-1 text-sm text-slate-600">Occupied beds</div>
            </a>
        </div>

        <div class="grid gap-6 xl:grid-cols-[1.35fr_0.65fr]">
            <div class="panel-card p-6">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900">Today’s appointments</h3>
                        <p class="text-sm text-slate-600">Live schedule overview</p>
                    </div>
                    <a href="{{ route('appointments.index') }}" class="text-sm font-semibold text-teal-600 hover:text-teal-700">View all</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr>
                                <th class="text-left">Patient</th>
                                <th class="text-left">Doctor</th>
                                <th class="text-left">Time</th>
                                <th class="text-left">Type</th>
                                <th class="text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentAppointments as $apt)
                                <tr>
                                    <td>{{ $apt->patient->full_name ?? '—' }}</td>
                                    <td>{{ $apt->provider->full_name ?? '—' }}</td>
                                    <td>{{ $apt->starts_at->format('g:i A') }}</td>
                                    <td>{{ $apt->appointmentType->name ?? '—' }}</td>
                                    <td>@include('partials.status-badge', ['label' => $apt->status, 'variant' => App\Support\QueueStatus::appointmentVariant($apt->status)])</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-6 text-center text-sm text-slate-500">No recent appointments.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="space-y-6">
                <div class="panel-card p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">Bed occupancy</h3>
                            <p class="text-sm text-slate-600">Current ward status</p>
                        </div>
                        <a href="{{ route('beds.index') }}" class="text-sm font-semibold text-teal-600 hover:text-teal-700">Open board</a>
                    </div>
                    @php $bedTotal = $bedOccupancy->sum(); @endphp
                    <div class="space-y-3">
                        @foreach (['AVAILABLE' => 'Available', 'OCCUPIED' => 'Occupied', 'RESERVED' => 'Reserved', 'CLEANING' => 'Cleaning', 'MAINTENANCE' => 'Maintenance'] as $key => $label)
                            @php
                                $barClass = match ($key) {
                                    'AVAILABLE' => 'bg-teal-500',
                                    'OCCUPIED' => 'bg-rose-500',
                                    'RESERVED' => 'bg-amber-500',
                                    'CLEANING' => 'bg-sky-500',
                                    default => 'bg-slate-500',
                                };
                            @endphp
                            <div>
                                <div class="mb-1 flex items-center justify-between text-sm">
                                    <span class="text-slate-600">{{ $label }}</span>
                                    <span class="font-semibold text-slate-900">{{ $bedOccupancy[$key] ?? 0 }}</span>
                                </div>
                                <div class="h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full {{ $barClass }}" style="width: <?= e($bedTotal ? (($bedOccupancy[$key] ?? 0) / $bedTotal) * 100 : 0) ?>%;"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="panel-card p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">ER queue</h3>
                            <p class="text-sm text-slate-600">Priority patients waiting</p>
                        </div>
                        <a href="{{ route('emergency.index') }}" class="text-sm font-semibold text-teal-600 hover:text-teal-700">View ER</a>
                    </div>
                    <div class="space-y-3">
                        @forelse ($erQueue as $q)
                            @php
                                $dashboardStatusClass = App\Support\QueueStatus::variant($q->status ?? null);
                            @endphp
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                                <div class="font-medium text-slate-900">{{ $q->erVisit->patient->full_name ?? '—' }}</div>
                                <div class="mt-2 flex items-center justify-between gap-3 text-sm">
                                    <span class="font-medium text-slate-700">{{ $q->priority }}</span>
                                    @include('partials.status-badge', ['label' => $q->status, 'variant' => $dashboardStatusClass])
                                </div>
                            </div>
                        @empty
                            <div class="rounded-2xl border border-dashed border-slate-200 p-4 text-center text-sm text-slate-500">ER queue is empty.</div>
                        @endforelse
                    </div>
                </div>

                <div class="panel-card p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">Follow-up due</h3>
                            <p class="text-sm text-slate-600">Patients needing a review</p>
                        </div>
                        <a href="{{ route('appointments.index', ['follow_up' => 'due']) }}" class="text-sm font-semibold text-teal-600 hover:text-teal-700">Open queue</a>
                    </div>
                    <div class="space-y-3">
                        @forelse ($followUpDue as $encounter)
                            <a href="{{ route('appointments.index', ['follow_up' => 'due']) }}" class="block rounded-2xl border border-slate-200 bg-slate-50 p-3 transition hover:border-teal-300 hover:bg-teal-50/40">
                                <div class="font-medium text-slate-900">{{ $encounter->patient->full_name ?? '—' }}</div>
                                <div class="mt-1 text-sm text-slate-600">{{ $encounter->follow_up_date?->format('M d, Y') }} · {{ $encounter->type }}</div>
                            </a>
                        @empty
                            <div class="rounded-2xl border border-dashed border-slate-200 p-4 text-center text-sm text-slate-500">No follow-up visits due soon.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    @elseif ($userRole === 'doctor')
        <section class="panel-card border-l-4 border-emerald-500 p-5">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.25em] text-emerald-600">Recommended next step</p>
                    <h3 class="mt-2 text-lg font-semibold text-slate-900">Review the highest-priority patient and start the consult</h3>
                </div>
                <a href="{{ route('doctors.queue') }}" class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Open queue</a>
            </div>
        </section>

        <section class="panel-card p-6 lg:p-8">
            <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.3em] text-teal-600">Clinical dashboard</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-900">My schedule</h2>
                </div>
                <div class="metric-pill">Today only</div>
            </div>
        </section>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <a href="{{ route('appointments.index') }}" class="panel-card h-full min-h-[220px] p-5 transition hover:-translate-y-1 hover:border-sky-400">
                <div class="flex items-center justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-50 text-sky-600"><i class="bi bi-calendar-event text-xl"></i></div>
                    <span class="status-pill info">Today</span>
                </div>
                <div class="mt-6 text-3xl font-semibold text-slate-900">{{ $myAppointments->count() }}</div>
                <div class="mt-1 text-sm text-slate-600">Appointments today</div>
            </a>
            <a href="{{ route('patients.index') }}" class="panel-card h-full min-h-[220px] p-5 transition hover:-translate-y-1 hover:border-emerald-400">
                <div class="flex items-center justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600"><i class="bi bi-person-check text-xl"></i></div>
                    <span class="status-pill success">Seen</span>
                </div>
                <div class="mt-6 text-3xl font-semibold text-slate-900">{{ $myPatientCount }}</div>
                <div class="mt-1 text-sm text-slate-600">Patients seen</div>
            </a>
            <a href="{{ route('appointments.index', ['follow_up' => 'due']) }}" class="panel-card h-full min-h-[220px] p-5 transition hover:-translate-y-1 hover:border-amber-400">
                <div class="flex items-center justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 text-amber-600"><i class="bi bi-alarm text-xl"></i></div>
                    <span class="status-pill warning">Due</span>
                </div>
                <div class="mt-6 text-3xl font-semibold text-slate-900">{{ $followUpDue->count() }}</div>
                <div class="mt-1 text-sm text-slate-600">Follow-up due</div>
            </a>
        </div>

        <div class="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
            <div class="panel-card p-6">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-900">Today’s appointments</h3>
                    <a href="{{ route('appointments.index') }}" class="text-sm font-semibold text-teal-600">View schedule</a>
                </div>
                <div class="space-y-3">
                    @forelse ($myAppointments as $apt)
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="font-medium text-slate-900">{{ $apt->patient->full_name ?? '—' }}</div>
                                    <div class="text-sm text-slate-600">{{ $apt->starts_at->format('g:i A') }} · {{ $apt->appointmentType->name ?? 'Visit' }}</div>
                                </div>
                                @include('partials.status-badge', ['label' => $apt->status, 'variant' => App\Support\QueueStatus::appointmentVariant($apt->status)])
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-200 p-4 text-sm text-slate-500">No appointments scheduled today.</div>
                    @endforelse
                </div>
            </div>

            <div class="panel-card p-6">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-900">Recent encounters</h3>
                    <a href="{{ route('encounters.index') }}" class="text-sm font-semibold text-teal-600">View</a>
                </div>
                <div class="space-y-3">
                    @forelse ($myEncounters as $enc)
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                            <div class="font-medium text-slate-900">{{ $enc->patient->full_name ?? '—' }}</div>
                            <div class="mt-1 text-sm text-slate-600">{{ $enc->started_at?->format('M d, Y') }} · {{ $enc->type }}</div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-200 p-4 text-sm text-slate-500">No recent encounters.</div>
                    @endforelse
                </div>
            </div>
        </div>
    @elseif ($userRole === 'nurse')
        <section class="panel-card border-l-4 border-teal-500 p-5">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.25em] text-teal-600">Recommended next step</p>
                    <h3 class="mt-2 text-lg font-semibold text-slate-900">Start triage for the next waiting patient</h3>
                </div>
                <a href="{{ route('emergency.index') }}" class="inline-flex items-center justify-center rounded-xl bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">Open triage</a>
            </div>
        </section>

        <section class="panel-card p-6 lg:p-8">
            <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.3em] text-teal-600">Care floor</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-900">Triage queue</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Monitor active triage and admissions from one view.</p>
                </div>
                <div class="metric-pill">Live</div>
            </div>
        </section>

        <div class="grid gap-4 md:grid-cols-2">
            <a href="{{ route('emergency.index') }}" class="panel-card h-full min-h-[220px] p-5 transition hover:-translate-y-1 hover:border-rose-400">
                <div class="flex items-center justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-50 text-rose-600"><i class="bi bi-clipboard-pulse text-xl"></i></div>
                    <span class="status-pill danger">Wait</span>
                </div>
                <div class="mt-6 text-3xl font-semibold text-slate-900">{{ $triageQueue->count() }}</div>
                <div class="mt-1 text-sm text-slate-600">Waiting triage</div>
            </a>
            <a href="{{ route('admissions.index') }}" class="panel-card h-full min-h-[220px] p-5 transition hover:-translate-y-1 hover:border-violet-400">
                <div class="flex items-center justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-50 text-violet-600"><i class="bi bi-building text-xl"></i></div>
                    <span class="status-pill warning">Pending</span>
                </div>
                <div class="mt-6 text-3xl font-semibold text-slate-900">{{ $pendingAdmissions->count() }}</div>
                <div class="mt-1 text-sm text-slate-600">Pending admissions</div>
            </a>
        </div>
    @elseif ($userRole === 'patient')
        <section class="panel-card border-l-4 border-emerald-500 p-5">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.25em] text-emerald-600">Pre-registration</p>
                    <h3 class="mt-2 text-lg font-semibold text-slate-900">Pre-register for your visit</h3>
                </div>
                @can('portal-dashboard')
                    <button type="button" class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700" data-bs-toggle="modal" data-bs-target="#preRegistrationModal">
                        Pre-register for your visit
                    </button>
                @endcan
            </div>
        </section>

        <section class="panel-card p-6 lg:p-8">
            <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.3em] text-teal-600">My care</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-900">My appointments</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Stay on top of upcoming visits and follow-up plans.</p>
                </div>
                <div class="metric-pill">Upcoming</div>
            </div>
        </section>

        <div class="grid gap-4 md:grid-cols-2">
            <a href="{{ route('patients.portal.appointments') }}" class="panel-card h-full min-h-[220px] p-5 transition hover:-translate-y-1 hover:border-sky-400">
                <div class="flex items-center justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-50 text-sky-600"><i class="bi bi-calendar-check text-xl"></i></div>
                    <span class="status-pill info">Booked</span>
                </div>
                <div class="mt-6 text-3xl font-semibold text-slate-900">{{ $myAppointments->count() }}</div>
                <div class="mt-1 text-sm text-slate-600">Appointments</div>
            </a>
            <a href="{{ route('patients.portal.appointments') }}" class="panel-card h-full min-h-[220px] p-5 transition hover:-translate-y-1 hover:border-emerald-400">
                <div class="flex items-center justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600"><i class="bi bi-bell text-xl"></i></div>
                    <span class="status-pill success">Ready</span>
                </div>
                <div class="mt-6 text-3xl font-semibold text-slate-900">{{ $myFollowUps->count() }}</div>
                <div class="mt-1 text-sm text-slate-600">Follow-up plans</div>
            </a>
        </div>

        <div class="panel-card p-6">
            <div class="space-y-3">
                @forelse ($myAppointments as $apt)
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                        <div class="font-medium text-slate-900">{{ $apt->provider->full_name ?? '—' }}</div>
                        <div class="mt-1 text-sm text-slate-600">{{ $apt->starts_at->format('M d, Y g:i A') }} · {{ $apt->appointmentType->name ?? 'Visit' }}</div>
                    </div>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-200 p-4 text-sm text-slate-500">No appointments scheduled.</div>
                @endforelse
            </div>
        </div>
    @elseif ($userRole === 'registration')
        <section class="panel-card p-6 lg:p-8">
            <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.3em] text-teal-600">Front desk</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-900">Registration desk</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Keep the flow moving for check-ins and scheduled visits.</p>
                </div>
                <div class="metric-pill">Today</div>
            </div>
        </section>

        <div class="grid gap-4 md:grid-cols-2">
            <a href="{{ route('appointments.index') }}" class="panel-card h-full min-h-[220px] p-5 transition hover:-translate-y-1 hover:border-sky-400">
                <div class="flex items-center justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-50 text-sky-600"><i class="bi bi-calendar2-week text-xl"></i></div>
                    <span class="status-pill info">Today</span>
                </div>
                <div class="mt-6 text-3xl font-semibold text-slate-900">{{ $registrationDeskQueue->count() }}</div>
                <div class="mt-1 text-sm text-slate-600">Scheduled today</div>
            </a>
            <a href="{{ route('appointments.index', ['status' => 'CHECKED_IN']) }}" class="panel-card h-full min-h-[220px] p-5 transition hover:-translate-y-1 hover:border-emerald-400">
                <div class="flex items-center justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600"><i class="bi bi-person-check text-xl"></i></div>
                    <span class="status-pill success">Checked</span>
                </div>
                <div class="mt-6 text-3xl font-semibold text-slate-900">{{ $checkedInToday }}</div>
                <div class="mt-1 text-sm text-slate-600">Checked in today</div>
            </a>
        </div>
    @elseif ($userRole === 'admin')
        <section class="panel-card p-6 lg:p-8">
            <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.3em] text-teal-600">Administration</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-900">Operational dashboard</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Access scheduling, provider coverage, reports, and account administration.</p>
                </div>
                <div class="metric-pill">Admin workspace</div>
            </div>
        </section>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <a href="{{ route('appointments.index') }}" class="panel-card p-5">
                <div class="text-3xl font-semibold text-slate-900">{{ $adminAppointmentCount }}</div>
                <div class="mt-1 text-sm text-slate-600">Appointments</div>
            </a>
            <a href="{{ route('appointment-reassignments.index') }}" class="panel-card p-5">
                <div class="text-3xl font-semibold text-slate-900">{{ $openReassignmentCount ?? 0 }}</div>
                <div class="mt-1 text-sm text-slate-600">Provider coverage cases</div>
            </a>
            <a href="{{ route('reports.index') }}" class="panel-card p-5">
                <div class="text-lg font-semibold text-slate-900">Operational reports</div>
                <div class="mt-1 text-sm text-slate-600">View reports</div>
            </a>
            @can('manage-users')
                <a href="{{ route('admin.users.index') }}" class="panel-card p-5">
                    <div class="text-lg font-semibold text-slate-900">User management</div>
                    <div class="mt-1 text-sm text-slate-600">Manage accounts and access</div>
                </a>
            @endcan
            @can('view-integration-logs')
                <a href="{{ route('admin.integrations.index') }}" class="panel-card p-5">
                    <div class="text-lg font-semibold text-slate-900">Integration deliveries</div>
                    <div class="mt-1 text-sm text-slate-600">Read-only delivery status</div>
                </a>
            @endcan
        </div>
    @else
        <section class="panel-card border-l-4 border-slate-300 p-6 lg:p-8">
            <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.3em] text-slate-500">Access</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-900">No dashboard modules assigned</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Your account is authenticated, but it does not have any HIMS dashboard permissions configured for this role. Contact an administrator to request access.</p>
                </div>
                <div class="metric-pill">Restricted</div>
            </div>
        </section>
@endif
</div>
@endsection
