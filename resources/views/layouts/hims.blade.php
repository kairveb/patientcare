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
                    <ul class="nav-list">
                        <li><a class="nav-link{{ request()->routeIs('dashboard') ? ' active' : '' }}" href="{{ route('dashboard') }}" aria-label="Dashboard" @if(request()->routeIs('dashboard')) aria-current="page" @endif><i class="ph-fill ph-squares-four" aria-hidden="true"></i><span class="nav-label">Dashboard</span></a></li>
                        <li><a class="nav-link{{ request()->routeIs('notifications.*') ? ' active' : '' }}" href="{{ route('notifications.index') }}" aria-label="Notifications"><i class="ph-fill ph-bell" aria-hidden="true"></i><span class="nav-label">Notifications</span></a></li>
                    </ul>

                    @php
                        $user = auth()->user();
                        $modules = [
                            [
                                'id' => 'nav-sprs',
                                'label' => 'Smart Patient Registration System (SPRS)',
                                'icon' => 'ph-users-three',
                                'items' => [
                                    ['label' => 'Patient List', 'route' => 'patients.index', 'permission' => 'view-patients', 'active' => ['patients.index', 'patients.show', 'patients.vitals']],
                                    ['label' => 'Patient Portal', 'route' => 'patients.portal', 'permission' => 'portal-dashboard', 'roles' => ['patient'], 'active' => ['patients.portal', 'patients.portal.*']],
                                    ['label' => 'Patient Profile', 'route' => 'patients.profile', 'roles' => ['patient'], 'active' => ['patients.profile', 'patients.profile.*']],
                                    ['label' => 'Profile', 'route' => 'profile.edit', 'active' => ['profile.edit']],
                                    ['label' => 'Privacy requests', 'route' => 'privacy.requests.queue', 'roles' => ['registration', 'admin', 'super-admin'], 'active' => ['privacy.requests.queue', 'privacy.requests.staff.*']],
                                    ['label' => 'My Privacy', 'route' => 'patients.portal.privacy', 'permission' => 'portal-dashboard', 'roles' => ['patient'], 'active' => ['patients.portal.privacy', 'privacy.requests.show']],
                                    ['label' => 'User Management', 'route' => 'admin.users.index', 'permission' => 'manage-users', 'roles' => ['admin', 'super-admin'], 'active' => ['admin.users.*']],
                                    ['label' => 'Reports', 'route' => 'reports.index', 'permission' => 'view-reports', 'roles' => ['nurse', 'registration', 'admin', 'super-admin'], 'active' => ['reports.*']],
                                    ['label' => 'Audit Logs', 'route' => 'audit.index', 'permission' => 'view-audit-logs', 'roles' => ['super-admin'], 'active' => ['audit.*']],
                                    ['label' => 'Integration deliveries', 'route' => 'admin.integrations.index', 'permission' => 'view-integration-logs', 'roles' => ['admin', 'super-admin'], 'active' => ['admin.integrations.*']],
                                ],
                            ],
                            [
                                'id' => 'nav-ass',
                                'label' => 'Appointment and Scheduling System (ASS)',
                                'icon' => 'ph-calendar-check',
                                'items' => [
                                    ['label' => 'Appointments', 'route' => 'appointments.index', 'permission' => 'view-appointments', 'active' => ['appointments.*']],
                                    ['label' => 'My Appointments', 'route' => 'patients.portal.appointments', 'permission' => 'portal-dashboard', 'roles' => ['patient'], 'active' => ['patients.portal.appointments']],
                                    ['label' => 'Appointment updates', 'route' => 'patient.reassignments.index', 'roles' => ['patient'], 'active' => ['patient.reassignments.*']],
                                    ['label' => 'Provider coverage', 'route' => 'appointment-reassignments.index', 'roles' => ['registration', 'admin', 'super-admin'], 'active' => ['appointment-reassignments.*']],
                                    ['label' => 'Report unavailability', 'route' => 'provider-unavailabilities.create', 'roles' => ['doctor'], 'active' => ['provider-unavailabilities.*']],
                                ],
                            ],
                            [
                                'id' => 'nav-tocs',
                                'label' => 'Telehealth and Outpatient Care System (TOCS)',
                                'icon' => 'ph-heartbeat',
                                'items' => [
                                    ['label' => 'Outpatient', 'route' => 'outpatient.index', 'permission' => 'view-encounters', 'active' => ['outpatient.*', 'encounters.*']],
                                    ['label' => 'Doctor Queue', 'route' => 'doctors.queue', 'permission' => 'view-opd-queue', 'roles' => ['doctor', 'nurse', 'registration', 'super-admin'], 'active' => ['doctors.queue*']],
                                    ['label' => 'Outpatient approvals', 'route' => 'care-requests.index', 'permission' => 'view-care-requests', 'roles' => ['doctor', 'nurse', 'registration', 'super-admin'], 'active' => ['care-requests.*']],
                                    ['label' => 'My Approvals', 'route' => 'patients.portal.approvals', 'permission' => 'view-care-requests', 'roles' => ['patient'], 'active' => ['patients.portal.approvals', 'patients.portal.approval']],
                                    ['label' => 'Telehealth', 'route' => 'telehealth.index', 'permission' => 'view-telehealth', 'roles' => ['doctor', 'nurse', 'super-admin'], 'active' => ['telehealth.*']],
                                    ['label' => 'My Telehealth', 'route' => 'patients.portal.telehealth', 'permission' => 'portal-dashboard', 'roles' => ['patient'], 'active' => ['patients.portal.telehealth*']],
                                    ['label' => 'Billing Rates', 'route' => 'billing.rates.index', 'permission' => 'manage-billing', 'roles' => ['super-admin'], 'active' => ['billing.rates.*']],
                                ],
                            ],
                            [
                                'id' => 'nav-eerts',
                                'label' => 'Emergency and ER Triage System (EERTS)',
                                'icon' => 'ph-first-aid-kit',
                                'items' => [
                                    ['label' => 'Triage', 'route' => 'triage.dashboard', 'permission' => 'triage-patients', 'roles' => ['nurse', 'doctor', 'super-admin'], 'active' => ['triage.*']],
                                    ['label' => 'ER / Emergency', 'route' => 'emergency.index', 'permission' => 'view-er', 'roles' => ['nurse', 'doctor', 'super-admin', 'registration'], 'active' => ['emergency.*']],
                                ],
                            ],
                            [
                                'id' => 'nav-ibms',
                                'label' => 'Inpatient and Bed Management System (IBMS)',
                                'icon' => 'ph-bed',
                                'items' => [
                                    ['label' => 'Bed Board', 'route' => 'beds.index', 'permission' => 'view-beds', 'roles' => ['nurse', 'doctor', 'registration', 'super-admin'], 'active' => ['beds.*', 'inpatient.*']],
                                    ['label' => 'Admissions', 'route' => 'admissions.index', 'permission' => 'view-admissions', 'roles' => ['nurse', 'doctor', 'registration', 'super-admin'], 'active' => ['admissions.*']],
                                    ['label' => 'Discharge summaries', 'route' => 'patients.portal.discharge-summaries', 'permission' => 'portal-dashboard', 'roles' => ['patient'], 'active' => ['patients.portal.discharge-summaries']],
                                    ['label' => 'Bed Setup', 'route' => 'admin.bed-setup.index', 'permissions' => ['manage-wards', 'manage-beds'], 'roles' => ['admin', 'super-admin'], 'active' => ['admin.bed-setup.*']],
                                ],
                            ],
                        ];
                    @endphp

                    @foreach ($modules as $module)
                        @php
                            $visibleItems = array_values(array_filter($module['items'], static function (array $item) use ($user): bool {
                                return (! isset($item['permission']) || $user->can($item['permission']))
                                    && (! isset($item['permissions']) || collect($item['permissions'])->every(fn (string $permission): bool => $user->can($permission)))
                                    && (! isset($item['roles']) || $user->hasAnyRole($item['roles']));
                            }));
                            $moduleActive = collect($visibleItems)->contains(
                                fn (array $item): bool => request()->routeIs(...$item['active'])
                            );
                        @endphp
                        @if ($visibleItems !== [])
                            <ul class="nav-list nav-domain-list">
                                <li class="nav-accordion{{ $moduleActive ? ' is-expanded is-active' : '' }}">
                                    <button class="nav-link nav-link-button nav-accordion__toggle nav-module-toggle" type="button" aria-expanded="{{ $moduleActive ? 'true' : 'false' }}" aria-controls="{{ $module['id'] }}" aria-label="{{ $module['label'] }}">
                                        <i class="ph-fill {{ $module['icon'] }}" aria-hidden="true"></i>
                                        <span class="nav-label">{{ $module['label'] }}</span>
                                        <i class="ph ph-caret-down nav-chevron" aria-hidden="true"></i>
                                    </button>
                                    <ul class="nav-submenu" id="{{ $module['id'] }}" @if(! $moduleActive) hidden @endif>
                                        @foreach ($visibleItems as $item)
                                            @php $itemActive = request()->routeIs(...$item['active']); @endphp
                                            <li>
                                                <a href="{{ route($item['route']) }}" class="{{ $itemActive ? 'active' : '' }}" @if($itemActive) aria-current="page" @endif>{{ $item['label'] }}</a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </li>
                            </ul>
                        @endif
                    @endforeach
                </nav>

                <footer class="sidebar-footer">
                    <div class="sidebar-profile-wrap">
                        <button class="sidebar-profile" id="profile-toggle" type="button" aria-label="Open account menu" aria-haspopup="menu" aria-expanded="false" aria-controls="profile-menu">
                            <span class="profile-avatar" aria-hidden="true">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                            <span class="profile-info"><span class="profile-name">{{ auth()->user()->name }}</span><span class="profile-role">{{ auth()->user()->roles->pluck('display_name')->unique()->join(', ') }}</span></span>
                            <i class="ph ph-caret-up-down profile-chevron" aria-hidden="true"></i>
                        </button>
                        <div class="profile-menu" id="profile-menu" role="menu" hidden>
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

            const idleMs = {{ (int) config('security.session_idle_minutes', 20) * 1000 * 60 }};
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
