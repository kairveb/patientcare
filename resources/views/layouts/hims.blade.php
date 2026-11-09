<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
      window.__APP_CONFIG__ = {
        apiBaseUrl: "{{ rtrim(config('app.url', env('APP_URL', '')), '/') }}"
      };
    </script>
    <title>@yield('title', 'HIMS') — {{ config('app.name', 'HIMS') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
<link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/components/typography-accessibility.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/pages/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/pages/dashboard-command-center.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="font-sans antialiased" data-module="@yield('module', 'main')" data-page="@yield('page', 'dashboard')">
    <div class="app-shell" data-auth-guard>
        <aside class="sidebar" id="app-sidebar" aria-label="Primary navigation">
            <div class="sidebar-panel">
                <header class="sidebar-header">
                    <a class="sidebar-logo" href="{{ route('dashboard') }}" aria-label="HIMS home">
                        <span class="logo-icon" aria-hidden="true"><i class="ph-fill ph-cross"></i></span>
                        <span class="logo-text">
                            <strong>HIMS</strong>
                            <span class="brand-tagline">Care Coordination</span>
                            <span class="brand-suite">Main System</span>
                        </span>
                    </a>
                </header>

                <nav class="sidebar-nav" aria-label="HIMS systems">
                    <p class="nav-title">Overview</p>
                    <ul class="nav-list">
                        <li><a class="nav-link{{ request()->routeIs('dashboard') ? ' active' : '' }}" href="{{ route('dashboard') }}" aria-label="Dashboard" @if(request()->routeIs('dashboard')) aria-current="page" @endif><i class="ph-fill ph-squares-four" aria-hidden="true"></i><span class="nav-label">Dashboard</span></a></li>
                        @if (auth()->user()->hasRole('patient'))
                            @can('portal-dashboard')
                                <li><a class="nav-link{{ request()->routeIs('patients.portal', 'patients.portal.appointments', 'patients.portal.history', 'patients.portal.telehealth', 'patients.portal.discharge-summaries', 'patients.portal.approvals', 'patients.portal.approval', 'patient.reassignments.*') ? ' active' : '' }}" href="{{ route('patients.portal') }}" aria-label="Patient Portal" @if(request()->routeIs('patients.portal', 'patients.portal.appointments', 'patients.portal.history', 'patients.portal.telehealth', 'patients.portal.discharge-summaries', 'patients.portal.approvals', 'patients.portal.approval', 'patient.reassignments.*')) aria-current="page" @endif><i class="ph-fill ph-user-circle" aria-hidden="true"></i><span class="nav-label">Patient Portal</span></a></li>
                                <li><a class="nav-link{{ request()->routeIs('patients.portal.privacy', 'privacy.requests.show') ? ' active' : '' }}" href="{{ route('patients.portal.privacy') }}" aria-label="My Privacy"><i class="ph-fill ph-shield-check" aria-hidden="true"></i><span class="nav-label">My Privacy</span></a></li>
                                <li><a class="nav-link{{ request()->routeIs('patient.reassignments.*') ? ' active' : '' }}" href="{{ route('patient.reassignments.index') }}" aria-label="Appointment updates" @if(request()->routeIs('patient.reassignments.*')) aria-current="page" @endif><i class="ph-fill ph-calendar-warning" aria-hidden="true"></i><span class="nav-label">Appointment updates</span></a></li>
                                <li><a class="nav-link{{ request()->routeIs('patients.portal.discharge-summaries') ? ' active' : '' }}" href="{{ route('patients.portal.discharge-summaries') }}" aria-label="My Discharge Summaries" @if(request()->routeIs('patients.portal.discharge-summaries')) aria-current="page" @endif><i class="ph-fill ph-file-text" aria-hidden="true"></i><span class="nav-label">Discharge summaries</span></a></li>
                                <li><a class="nav-link{{ request()->routeIs('patients.portal.approvals', 'patients.portal.approval') ? ' active' : '' }}" href="{{ route('patients.portal.approvals') }}" aria-label="My Approvals" @if(request()->routeIs('patients.portal.approvals', 'patients.portal.approval')) aria-current="page" @endif><i class="ph-fill ph-file-check" aria-hidden="true"></i><span class="nav-label">My Approvals</span></a></li>
                            @endcan
                        @endif
                    </ul>

                    @php
                        $user = auth()->user();
                        $showHospitalSystemsSection = ! $user->hasRole('patient');

                        if ($showHospitalSystemsSection) {
                            $showHospitalSystemsSection = $user->can('view-patients')
                                || $user->can('view-appointments')
                                || $user->can('view-encounters')
                                || $user->can('view-telehealth')
                                || $user->can('view-er')
                                || $user->can('view-beds')
                                || $user->can('view-admissions')
                                || $user->can('view-reports')
                                || $user->can('view-audit-logs');
                        }
                    @endphp
                    @if ($showHospitalSystemsSection)
                    <p class="nav-title">Hospital Systems</p>
                    <ul class="nav-list nav-domain-list">
                        @if (auth()->user()->hasAnyRole(['admin','registration','super-admin']))
                            <li><a class="nav-link{{ request()->routeIs('appointment-reassignments.*') ? ' active' : '' }}" href="{{ route('appointment-reassignments.index') }}" aria-label="Provider coverage" @if(request()->routeIs('appointment-reassignments.*')) aria-current="page" @endif><i class="ph-fill ph-calendar-x" aria-hidden="true"></i><span class="nav-label">Provider coverage</span></a></li>
                        @elseif (auth()->user()->hasRole('doctor'))
                            <li><a class="nav-link{{ request()->routeIs('provider-unavailabilities.*') ? ' active' : '' }}" href="{{ route('provider-unavailabilities.create') }}" aria-label="Report unavailability" @if(request()->routeIs('provider-unavailabilities.*')) aria-current="page" @endif><i class="ph-fill ph-calendar-x" aria-hidden="true"></i><span class="nav-label">Report unavailability</span></a></li>
                        @elseif (auth()->user()->hasRole('nurse') && auth()->user()->can('manage-provider-schedules'))
                            <li><a class="nav-link{{ request()->routeIs('provider-unavailabilities.*') ? ' active' : '' }}" href="{{ route('provider-unavailabilities.create') }}" aria-label="Report unavailability" @if(request()->routeIs('provider-unavailabilities.*')) aria-current="page" @endif><i class="ph-fill ph-calendar-x" aria-hidden="true"></i><span class="nav-label">Report unavailability</span></a></li>
                        @endif
                        @if (auth()->user()->hasAnyRole(['registration', 'admin', 'super-admin']))
                            <li><a class="nav-link{{ request()->routeIs('privacy.requests.queue') ? ' active' : '' }}" href="{{ route('privacy.requests.queue') }}" aria-label="Data subject requests"><i class="ph-fill ph-lock-key" aria-hidden="true"></i><span class="nav-label">Privacy requests</span></a></li>
                        @endif
                        @can('view-patients')
                        <li class="nav-accordion{{ request()->routeIs('patients.*') ? ' is-expanded is-active' : '' }}">
                            <button class="nav-link nav-link-button nav-accordion__toggle" type="button" aria-expanded="{{ request()->routeIs('patients.*') ? 'true' : 'false' }}" aria-controls="nav-patients" aria-label="Patient Management"><i class="ph-fill ph-users-three" aria-hidden="true"></i><span class="nav-label">Patients</span><i class="ph ph-caret-down nav-chevron" aria-hidden="true"></i></button>
                            <ul class="nav-submenu" id="nav-patients" @if(!request()->routeIs('patients.*')) hidden @endif>
                                @if (auth()->user()->hasAnyRole(['registration','doctor','nurse','super-admin']))
                                    <li><a href="{{ route('patients.index') }}" class="{{ request()->routeIs('patients.index', 'patients.show', 'patients.vitals') ? 'active' : '' }}">Patient List</a></li>
                                @endif
                            </ul>
                        </li>
                        @endcan

                        @php
                            $isPatientUser = auth()->user()->hasRole('patient');
                            $careDeliveryVisibleLinks = [];
                            if (auth()->user()->can('view-appointments')) { $careDeliveryVisibleLinks[] = 'appointments'; }
                            if (auth()->user()->can('view-encounters')) { $careDeliveryVisibleLinks[] = 'encounters'; }
                            if (auth()->user()->can('view-care-requests') && auth()->user()->hasAnyRole(['doctor','nurse','registration','super-admin'])) { $careDeliveryVisibleLinks[] = 'care-requests'; }
                            if (auth()->user()->can('view-telehealth') && auth()->user()->hasAnyRole(['doctor','nurse','super-admin'])) { $careDeliveryVisibleLinks[] = 'telehealth'; }
                            if (auth()->user()->can('view-er') && auth()->user()->hasAnyRole(['nurse','doctor','super-admin','registration'])) { $careDeliveryVisibleLinks[] = 'er'; }
                        @endphp
                        @if (! $isPatientUser || ! empty($careDeliveryVisibleLinks))
                        <li class="nav-accordion{{ request()->routeIs('appointments.*', 'outpatient.*', 'encounters.*', 'care-requests.*', 'telehealth.*', 'emergency.*', 'doctors.queue*') ? ' is-expanded is-active' : '' }}">
                            <button class="nav-link nav-link-button nav-accordion__toggle" type="button" aria-expanded="{{ request()->routeIs('appointments.*', 'outpatient.*', 'encounters.*', 'care-requests.*', 'telehealth.*', 'emergency.*', 'doctors.queue*') ? 'true' : 'false' }}" aria-controls="nav-care" aria-label="Care Delivery"><i class="ph-fill ph-heartbeat" aria-hidden="true"></i><span class="nav-label">Care Delivery</span><i class="ph ph-caret-down nav-chevron" aria-hidden="true"></i></button>
                            <ul class="nav-submenu" id="nav-care" @if(!request()->routeIs('appointments.*', 'outpatient.*', 'encounters.*', 'care-requests.*', 'telehealth.*', 'emergency.*', 'doctors.queue*')) hidden @endif>
                                @can('view-appointments')
                                    <li><a href="{{ route('appointments.index') }}" class="{{ request()->routeIs('appointments.*') ? 'active' : '' }}">Appointments</a></li>
                                @endcan
                                @can('view-encounters')
                                    @if (auth()->user()->hasAnyRole(['doctor','super-admin','nurse']))
                                        <li><a href="{{ route('outpatient.index') }}" class="{{ request()->routeIs('outpatient.index', 'encounters.*') ? 'active' : '' }}">Outpatient</a></li>
                                    @endif
                                    @if (auth()->user()->hasAnyRole(['doctor','super-admin']))
                                        <li><a href="{{ route('doctors.queue') }}" class="{{ request()->routeIs('doctors.queue') ? 'active' : '' }}">Doctor Queue</a></li>
                                    @endif
                                @endcan
                                @can('view-care-requests')
                                    @if (auth()->user()->hasAnyRole(['doctor','nurse','registration','super-admin']))
                                        <li><a href="{{ route('care-requests.index') }}" class="{{ request()->routeIs('care-requests.*') ? 'active' : '' }}">Outpatient approvals</a></li>
                                    @endif
                                @endcan
                                @can('view-telehealth')
                                    @if (auth()->user()->hasAnyRole(['doctor','nurse','super-admin']))
                                        <li><a href="{{ route('telehealth.index') }}" class="{{ request()->routeIs('telehealth.*') ? 'active' : '' }}">Telehealth</a></li>
                                    @endif
                                @endcan
                                @can('view-er')
                                    @if (auth()->user()->hasAnyRole(['nurse','doctor','super-admin','registration']))
                                        <li><a href="{{ route('emergency.index') }}" class="{{ request()->routeIs('emergency.*') ? 'active' : '' }}">ER / Emergency</a></li>
                                    @endif
                                @endcan
                            </ul>
                        </li>
                        @endif

                        @php
                            $inpatientVisibleLinks = [];
                            if ($user->can('view-beds') && $user->hasAnyRole(['nurse', 'doctor', 'registration', 'super-admin'])) {
                                $inpatientVisibleLinks[] = 'beds';
                            }
                            if ($user->can('view-admissions') && $user->hasAnyRole(['nurse', 'doctor', 'registration', 'super-admin'])) {
                                $inpatientVisibleLinks[] = 'admissions';
                            }
                        @endphp
                        @if (! empty($inpatientVisibleLinks))
                        <li class="nav-accordion{{ request()->routeIs('beds.*', 'admissions.*', 'inpatient.*') ? ' is-expanded is-active' : '' }}">
                            <button class="nav-link nav-link-button nav-accordion__toggle" type="button" aria-expanded="{{ request()->routeIs('beds.*', 'admissions.*', 'inpatient.*') ? 'true' : 'false' }}" aria-controls="nav-inpatient" aria-label="Inpatient Services"><i class="ph-fill ph-bed" aria-hidden="true"></i><span class="nav-label">Inpatient</span><i class="ph ph-caret-down nav-chevron" aria-hidden="true"></i></button>
                            <ul class="nav-submenu" id="nav-inpatient" @if(!request()->routeIs('beds.*', 'admissions.*', 'inpatient.*')) hidden @endif>
                                @if (in_array('beds', $inpatientVisibleLinks, true))
                                    <li><a href="{{ route('beds.index') }}" class="{{ request()->routeIs('beds.index', 'inpatient.index') ? 'active' : '' }}">Bed Board</a></li>
                                @endif
                                @if (in_array('admissions', $inpatientVisibleLinks, true))
                                    <li><a href="{{ route('admissions.index') }}" class="{{ request()->routeIs('admissions.*') ? 'active' : '' }}">Admissions</a></li>
                                @endif
                            </ul>
                        </li>
                        @endif

                        @php
                            $canViewReports = $user->can('view-reports') && $user->hasAnyRole(['admin', 'super-admin']);
                            $canViewAuditLogs = $user->can('view-audit-logs') && $user->hasRole('super-admin');
                            $showOperationsMenu = $canViewReports || $canViewAuditLogs;
                        @endphp
                        @if ($showOperationsMenu)
                        <li class="nav-accordion{{ request()->routeIs('reports.*', 'audit.*') ? ' is-expanded is-active' : '' }}">
                            <button class="nav-link nav-link-button nav-accordion__toggle" type="button" aria-expanded="{{ request()->routeIs('reports.*', 'audit.*') ? 'true' : 'false' }}" aria-controls="nav-ops" aria-label="Operations"><i class="ph-fill ph-chart-pie-slice" aria-hidden="true"></i><span class="nav-label">Operations</span><i class="ph ph-caret-down nav-chevron" aria-hidden="true"></i></button>
                            <ul class="nav-submenu" id="nav-ops" @if(!request()->routeIs('reports.*', 'audit.*')) hidden @endif>
                                @if ($canViewReports)
                                    <li><a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}">Reports</a></li>
                                @endif
                                @if ($canViewAuditLogs)
                                    <li><a href="{{ route('audit.index') }}" class="{{ request()->routeIs('audit.*') ? 'active' : '' }}">Audit Logs</a></li>
                                @endif
                                @if ($user->hasRole('super-admin') && $user->can('manage-billing'))
                                    <li><a href="{{ route('billing.rates.index') }}" class="{{ request()->routeIs('billing.rates.*') ? 'active' : '' }}">Billing Rates</a></li>
                                @endif
                            </ul>
                        </li>
                        @endif
                    </ul>
                    @endif
                    @if ($user->hasAnyRole(['admin', 'super-admin']))
                        <p class="nav-title">Administration</p>
                        <ul class="nav-list">
                            @can('manage-users')
                                <li><a class="nav-link{{ request()->routeIs('admin.users.*') ? ' active' : '' }}" href="{{ route('admin.users.index') }}"><i class="ph-fill ph-users" aria-hidden="true"></i><span class="nav-label">User Management</span></a></li>
                            @endcan
                            @if ($user->can('manage-wards') && $user->can('manage-beds'))
                                <li><a class="nav-link{{ request()->routeIs('admin.bed-setup.*') ? ' active' : '' }}" href="{{ route('admin.bed-setup.index') }}"><i class="ph-fill ph-bed" aria-hidden="true"></i><span class="nav-label">Bed Setup</span></a></li>
                            @endif
                        </ul>
                    @endif
                </nav>

                <footer class="sidebar-footer">
                    <div class="sidebar-profile-wrap">
                        <button class="sidebar-profile" id="profile-toggle" type="button" aria-label="Open account menu" aria-haspopup="menu" aria-expanded="false" aria-controls="profile-menu">
                            <span class="profile-avatar" aria-hidden="true">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                            <span class="profile-info"><span class="profile-name">{{ auth()->user()->name }}</span><span class="profile-role">{{ auth()->user()->roles->pluck('display_name')->unique()->join(', ') }}</span></span>
                            <i class="ph ph-caret-up-down profile-chevron" aria-hidden="true"></i>
                        </button>
                        <div class="profile-menu" id="profile-menu" role="menu" hidden>
                            <a class="profile-menu-link" href="{{ route('profile.edit') }}" role="menuitem"><i class="ph ph-user" aria-hidden="true"></i>Profile</a>
                            <div class="profile-menu-divider" role="separator"></div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="profile-menu-link profile-menu-logout" type="submit" role="menuitem"><i class="ph ph-sign-out" aria-hidden="true"></i>Logout</button>
                            </form>
                        </div>
                    </div>
                </footer>
            </div>
        </aside>
        <div class="sidebar-backdrop" data-sidebar-backdrop aria-hidden="true"></div>

        <main class="main-content" id="main-content">
            <header class="navbar-custom">
                <div class="navbar-left">
                    <button class="menu-toggle" type="button" aria-label="Collapse sidebar" aria-controls="app-sidebar" aria-expanded="true"><i class="ph ph-list" aria-hidden="true"></i></button>
                    <div class="app-identity"><p class="app-identity-title">HIMS Main System</p><span class="app-identity-subtitle">Patient Access &amp; Care Coordination</span></div>
                </div>
                <div class="navbar-center"></div>
                @if (false)
                <div class="navbar-right">
                    <button class="icon-btn" type="button" aria-label="Notifications" disabled><i class="ph ph-bell" aria-hidden="true"></i></button>
                    <button class="icon-btn" type="button" aria-label="Messages" disabled><i class="ph ph-envelope-simple" aria-hidden="true"></i></button>
                </div>
                @endif
            </header>

            <section class="page-wrapper" aria-label="@yield('page-label', 'HIMS workspace')">
                <div class="dashboard-page">
                    @hasSection('page-title')
                        <header class="dashboard-header motion-enter">
                            <div class="dashboard-header-main">
                                @hasSection('page-kicker')
                                    <p class="dashboard-kicker">@yield('page-kicker', 'Operations')</p>
                                @endif
                                <h1>@yield('page-title', 'Operations')</h1>
                                <p class="dashboard-lead">@yield('page-description', 'A modern operating view for patient access and care coordination.')</p>
                            </div>
                            <div class="dashboard-header-actions">
                                <div class="dashboard-session">
                                    <strong>{{ auth()->user()->name }}</strong>
                                    <span>{{ auth()->user()->roles->pluck('display_name')->unique()->join(', ') }}</span>
                                </div>
                            </div>
                        </header>
                    @endif

                    @if (session('success'))
                        <div class="hims-notification hims-notification--success hims-notification--banner">{{ session('success') }}</div>
                    @endif
                    @if (! config('security.otp_enabled', true) && auth()->user()->hasAnyRole(['admin', 'super-admin']))
                        <div role="alert" class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm font-medium text-amber-900">
                            Security warning: email one-time password verification is disabled.
                        </div>
                    @endif
                    @if (auth()->user()->hasAnyRole(['admin', 'super-admin']) && (config('telehealth.provider') === 'jitsi_demo' || (blank(config('telehealth.provider')) && ! app(\App\Services\ZoomService::class)->enabled())))
                        <div role="alert" class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm font-semibold text-amber-950">
                            Demo video mode: not suitable for real patient consultations.
                        </div>
                    @endif
                    @if (session('error'))
                        <div class="hims-notification hims-notification--danger hims-notification--banner">{{ session('error') }}</div>
                    @endif
                    @if ($errors->any())
                        <div class="hims-notification hims-notification--danger hims-notification--banner">
                            <strong>Please fix the following errors:</strong>
                            <div>
                                <ul class="mt-2 list-disc list-inside">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif
                    @yield('content')
                </div>
            </section>
        </main>
    </div>

    <div id="modal-portal" aria-live="polite"></div>
    <div id="toast-container" role="status" aria-live="polite" aria-atomic="true"></div>
    <dialog id="idle-session-warning" aria-labelledby="idle-session-title" class="fixed inset-0 m-auto w-[min(92vw,28rem)] rounded-xl border border-amber-300 bg-white p-6 shadow-xl backdrop:bg-slate-900/50">
        <h2 id="idle-session-title" class="text-lg font-semibold text-slate-900">Your session is about to expire</h2>
        <p class="mt-2 text-sm text-slate-600">For your privacy, you will be signed out in <strong id="idle-session-countdown">30</strong> seconds due to inactivity.</p>
        <div class="mt-5 flex justify-end">
            <button id="idle-session-stay" type="button" class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800">Stay signed in</button>
        </div>
    </dialog>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('assets/js/core/theme-boot.js') }}"></script>
    <script src="{{ asset('assets/js/data/module-registry.js') }}"></script>
    <script src="{{ asset('assets/js/core/app-shell.js') }}"></script>
    <script src="{{ asset('assets/js/core/password-toggle.js') }}"></script>
    <script>
        (() => {
            const warning = document.getElementById('idle-session-warning');
            if (!warning || @json(request()->routeIs('telehealth.join', 'telehealth.connect'))) return;

            const idleMs = {{ (int) config('security.session_idle_minutes', 5) * 1000 * 60 }};
            const warningMs = Math.min({{ (int) config('security.session_warning_seconds', 30) * 1000 }}, idleMs);
            const heartbeatUrl = @json(route('session.heartbeat'));
            const logoutUrl = @json(route('logout'));
            const loginUrl = @json(route('login'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const stayButton = document.getElementById('idle-session-stay');
            const countdown = document.getElementById('idle-session-countdown');
            let warningTimer;
            let expiryTimer;
            let countdownTimer;
            let lastHeartbeatAt = 0;
            let heartbeatInFlight = null;

            function clearTimers() {
                window.clearTimeout(warningTimer);
                window.clearTimeout(expiryTimer);
                window.clearInterval(countdownTimer);
            }

            function startCountdown() {
                let secondsLeft = Math.ceil(warningMs / 1000);
                countdown.textContent = String(secondsLeft);
                countdownTimer = window.setInterval(() => {
                    secondsLeft -= 1;
                    countdown.textContent = String(Math.max(0, secondsLeft));
                }, 1000);
            }

            async function confirmActivity() {
                if (heartbeatInFlight) return heartbeatInFlight;
                lastHeartbeatAt = Date.now();
                heartbeatInFlight = (async () => {
                try {
                    const response = await fetch(heartbeatUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    if (response.ok) {
                        return true;
                    }
                } catch (error) {
                    console.error('Unable to renew the session.', error);
                } finally {
                    heartbeatInFlight = null;
                }

                window.location.assign(loginUrl + '?timeout=1');
                return false;
                })();

                return heartbeatInFlight;
            }

            async function expireSession() {
                clearTimers();
                try {
                    await fetch(logoutUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                } catch (error) {
                    console.error('Unable to end the expired session.', error);
                } finally {
                    window.location.assign(loginUrl + '?timeout=1');
                }
            }

            function armTimers() {
                clearTimers();
                if (warning.open) warning.close();
                warningTimer = window.setTimeout(() => {
                    warning.showModal();
                    startCountdown();
                }, Math.max(0, idleMs - warningMs));
                expiryTimer = window.setTimeout(expireSession, idleMs);
            }

            stayButton.addEventListener('click', async () => {
                if (await confirmActivity()) {
                    warning.close();
                    armTimers();
                }
            });
            ['pointerdown', 'keydown', 'scroll', 'touchstart'].forEach((eventName) => {
                document.addEventListener(eventName, () => {
                    armTimers();
                    if (Date.now() - lastHeartbeatAt >= 60000) {
                        confirmActivity();
                    }
                }, { capture: true, passive: true });
            });
            armTimers();
        })();
    </script>
    @stack('scripts')
    <footer class="border-t border-slate-200 bg-white px-4 py-4 text-center text-sm text-slate-600">
        <a class="underline" href="{{ route('privacy.notice') }}">Privacy Notice</a>
        <span class="px-2">·</span>
        <a class="underline" href="{{ route('privacy.terms') }}">Terms and Agreement</a>
    </footer>
</body>
</html>
