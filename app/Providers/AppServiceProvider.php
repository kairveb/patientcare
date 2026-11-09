<?php

namespace App\Providers;

use App\Contracts\Icd10CodeValidator;
use App\Models\Permission;
use App\Models\CareRequest;
use App\Models\AppointmentReassignment;
use App\Models\ProviderUnavailability;
use App\Models\TelehealthSession;
use App\Models\DataSubjectRequest;
use App\Services\Icd10FormatValidator;
use App\Policies\CareRequestPolicy;
use App\Policies\AppointmentReassignmentPolicy;
use App\Policies\ProviderUnavailabilityPolicy;
use App\Policies\TelehealthSessionPolicy;
use App\Policies\ProviderPolicy;
use App\Policies\DataSubjectRequestPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use App\Models\Department;
use App\Models\Provider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Icd10CodeValidator::class, Icd10FormatValidator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(CareRequest::class, CareRequestPolicy::class);
        Gate::policy(AppointmentReassignment::class, AppointmentReassignmentPolicy::class);
        Gate::policy(ProviderUnavailability::class, ProviderUnavailabilityPolicy::class);
        Gate::policy(Provider::class, ProviderPolicy::class);
        Gate::policy(DataSubjectRequest::class, DataSubjectRequestPolicy::class);
        Gate::policy(TelehealthSession::class, TelehealthSessionPolicy::class);
        View::composer(['reports.index', 'reports._date-form'], function ($view): void {
            $view->with([
                'reportDepartments' => Department::query()->orderBy('name')->get(['id', 'name']),
                'reportDoctors' => Provider::query()->where('active', true)->orderBy('display_name')->get(['id', 'display_name']),
            ]);
        });

        if ($this->app->environment('production')) {
            URL::forceRootUrl(rtrim((string) config('app.url'), '/'));
            URL::forceScheme('https');
        }

        $this->registerRoleBladeDirective();
        $this->registerPermissionGates();
        $this->registerSensitiveReadRateLimits();
    }

    protected function registerRoleBladeDirective(): void
    {
        Blade::directive('role', function ($expression) {
            return "<?php if (auth()->check() && auth()->user()->hasRole({$expression})): ?>";
        });

        Blade::directive('endrole', function () {
            return '<?php endif; ?>';
        });
    }

    protected function registerSensitiveReadRateLimits(): void
    {
        RateLimiter::for('patient-reference-code', function (Request $request) {
            $user = $request->user();
            $key = $user
                ? "patient-reference-code:user:{$user->id}"
                : 'patient-reference-code:ip:'.$request->ip();

            return Limit::perMinute(10)->by($key);
        });

        RateLimiter::for('patient-reference-workflow', function (Request $request) {
            $user = $request->user();
            $key = $user
                ? "patient-reference-workflow:user:{$user->id}"
                : 'patient-reference-workflow:ip:'.$request->ip();

            return Limit::perMinute(60)->by($key);
        });

        RateLimiter::for('patient-search', function (Request $request) {
            $user = $request->user();
            $role = $user?->roles()->value('name') ?? 'guest';
            $limit = match ($role) {
                'super-admin' => 60,
                'registration' => 30,
                default => 15,
            };
            $key = $user ? "patient-search:{$role}:{$user->id}" : 'patient-search:ip:'.$request->ip();

            return Limit::perMinute($limit)->by($key);
        });

        RateLimiter::for('encounter-read', function (Request $request) {
            $user = $request->user();
            $role = $user?->roles()->value('name') ?? 'guest';
            $limit = match ($role) {
                'super-admin' => 60,
                'doctor' => 30,
                default => 15,
            };
            $key = $user ? "encounter-read:{$role}:{$user->id}" : 'encounter-read:ip:'.$request->ip();

            return Limit::perMinute($limit)->by($key);
        });

        RateLimiter::for('symptom-triage', function (Request $request) {
            $user = $request->user();
            $key = $user
                ? "symptom-triage:user:{$user->id}"
                : 'symptom-triage:ip:'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('patient-data-print', function (Request $request) {
            $user = $request->user();
            $key = $user
                ? "patient-data-print:user:{$user->id}:printable-copy"
                : 'patient-data-print:ip:'.$request->ip().':printable-copy';

            return Limit::perMinute(30)
                ->by($key)
                ->response(fn (Request $request, array $headers) => response()->view(
                    'errors.print-copy-rate-limited',
                    [],
                    429,
                    $headers
                ));
        });
    }

    /**
     * Register a Gate for every permission record so that
     * Gate::allows('view-patients') etc. resolve through the user's roles.
     */
    protected function registerPermissionGates(): void
    {
        try {
            foreach (Permission::all() as $permission) {
                Gate::define($permission->name, function ($user) use ($permission) {
                    return $user->isSuperAdmin() || $user->hasPermission($permission->name);
                });
            }
        } catch (\Throwable $e) {
            // Database may not be migrated yet (e.g., during install). Skip gracefully.
        }

        Gate::define('portal-dashboard', fn ($user) => $user->hasRole('patient'));
        Gate::define('view-own-medical-history', fn ($user) => $user->hasRole('patient'));
        Gate::define('view-own-appointments', fn ($user) => $user->hasRole('patient'));
        Gate::define('view-own-telehealth', fn ($user) => $user->hasRole('patient'));

        // Super admin bypass and dynamic permission checks for seeded permissions.
        Gate::before(function ($user, $ability) {
            if ($user->isSuperAdmin() && $ability === 'complete') {
                return null;
            }

            if ($user->isSuperAdmin()) {
                return true;
            }

            if (in_array($ability, ['portal-dashboard', 'view-own-medical-history', 'view-own-appointments', 'view-own-telehealth'], true)) {
                return $user->hasRole('patient');
            }

            try {
                if (Permission::where('name', $ability)->exists()) {
                    return $user->hasPermission($ability);
                }
            } catch (\Throwable $e) {
                // Database not ready yet while bootstrapping.
            }

            return null;
        });
    }
}
