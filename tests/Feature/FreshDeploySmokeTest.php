<?php

namespace Tests\Feature;

use App\Models\PatientConsent;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FreshDeploySmokeTest extends TestCase
{
    public function test_seed_order_allows_each_role_into_its_main_pages_and_denies_disallowed_pages(): void
    {
        config(['security.otp_enabled' => false]);

        $this->seed(DatabaseSeeder::class);

        $patient = User::query()->where('email', 'patient@coor.test')->firstOrFail();
        $patientRecord = $patient->patient()->firstOrFail();
        foreach (['DATA_PRIVACY', 'TERMS_OF_USE'] as $type) {
            PatientConsent::query()->firstOrCreate(
                ['patient_id' => $patientRecord->id, 'type' => $type, 'version' => config("consents.types.{$type}.version")],
                [
                    'granted' => true,
                    'recorded_at' => now(),
                    'recorded_by' => $patient->id,
                    'channel' => 'self',
                ]
            );
        }

        $admin = User::query()->firstOrCreate(
            ['email' => 'smoke-admin@coor.test'],
            [
                'name' => 'Smoke Admin',
                'password' => Hash::make('Password123!'),
                'email_verified_at' => now(),
                'last_activity_at' => now(),
            ]
        );
        $admin->roles()->syncWithoutDetaching([Role::query()->where('name', 'admin')->value('id')]);

        $rolePages = [
            'patient' => [
                ['/dashboard', 200],
                ['/patient-portal', 200],
                ['/patient-portal/appointments', 200],
                ['/patient-portal/privacy', 200],
                ['/doctors', 200],
            ],
            'doctor' => [
                ['/dashboard', 200],
                ['/appointments', 200],
                ['/doctors/queue', 200],
                ['/telehealth', 200],
                ['/reports/ai-evaluation', 200],
            ],
            'nurse' => [
                ['/dashboard', 200],
                ['/appointments', 200],
                ['/appointments/approvals', 200],
                ['/emergency', 200],
                ['/reports', 200],
            ],
            'registration' => [
                ['/dashboard', 200],
                ['/patients', 200],
                ['/appointments/approvals', 200],
                ['/reports', 200],
                ['/privacy-requests', 200],
                ['/provider-profiles', 200],
            ],
            'admin' => [
                ['/dashboard', 200],
                ['/appointments/approvals', 200],
                ['/reports', 200],
                ['/privacy-requests', 200],
                ['/provider-profiles', 200],
                ['/admin/bed-setup', 200],
                ['/admin/users', 200],
            ],
            'super-admin' => [
                ['/dashboard', 200],
                ['/appointments/approvals', 200],
                ['/reports', 200],
                ['/privacy-requests', 200],
                ['/provider-profiles', 200],
                ['/admin/users', 200],
                ['/audit-logs', 200],
            ],
        ];

        $forbiddenPages = [
            'patient' => ['/reports', '/privacy-requests', '/appointments/approvals'],
            'doctor' => ['/privacy-requests', '/appointments/approvals'],
            'nurse' => ['/privacy-requests'],
            'registration' => ['/telehealth'],
            'admin' => ['/telehealth'],
            'super-admin' => [],
        ];

        foreach ($rolePages as $roleName => $pages) {
            $user = $roleName === 'patient'
                ? $patient
                : ($roleName === 'admin' ? $admin : User::query()->whereHas('roles', fn ($query) => $query->where('name', $roleName))->firstOrFail());
            $user->forceFill([
                'email_verified_at' => now(),
                'last_activity_at' => now(),
                'is_active' => true,
            ])->save();

            $this->post(route('login'), [
                'email' => $user->email,
                'password' => 'Password123!',
            ])->assertRedirect();

            foreach ($pages as [$uri, $status]) {
                $response = $this->get($uri);
                $this->assertSame(
                    $status,
                    $response->getStatusCode(),
                    "{$roleName} could not open {$uri}; redirected to ".$response->headers->get('Location')
                );
            }

            foreach ($forbiddenPages[$roleName] as $uri) {
                $this->get($uri)->assertForbidden();
            }

            $this->post(route('logout'))->assertRedirect('/');
        }
    }

    public function test_production_database_seeder_skips_demo_data_and_seeds_access_control(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', [
            '--class' => DatabaseSeeder::class,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('roles', ['name' => 'super-admin']);
        $this->assertDatabaseHas('roles', ['name' => 'admin']);
        $this->assertDatabaseHas('permissions', ['name' => 'view-reports']);
        $this->assertDatabaseHas('permissions', ['name' => 'create-care-requests']);
        $this->assertDatabaseHas('permissions', ['name' => 'approve-care-requests']);
        $this->assertDatabaseMissing('users', ['email' => 'patient@coor.test']);

        $roleCount = Role::count();
        $permissionCount = Permission::count();
        $adminPermissionCount = Role::where('name', 'admin')->firstOrFail()->permissions()->count();

        $this->artisan('db:seed', [
            '--class' => DatabaseSeeder::class,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame($roleCount, Role::count());
        $this->assertSame($permissionCount, Permission::count());
        $this->assertSame(
            $adminPermissionCount,
            Role::where('name', 'admin')->firstOrFail()->permissions()->count()
        );
    }
}
