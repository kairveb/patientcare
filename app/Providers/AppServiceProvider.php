<?php

namespace App\Providers;

use App\Contracts\Icd10CodeValidator;
use App\Models\Permission;
use App\Services\Icd10FormatValidator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
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
