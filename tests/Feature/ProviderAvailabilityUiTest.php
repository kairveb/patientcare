<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCurrentPrivacyAcceptance;
use App\Models\AppointmentSlot;
use App\Models\Role;
use App\Models\Provider;
use App\Models\User;
use Database\Seeders\DemoProvidersAndSlotsSeeder;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderAvailabilityUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_active_providers_are_available_to_super_admin_and_registration_pages(): void
    {
        $provider = Provider::query()->where('display_name', 'Dr. Elena Santos')->firstOrFail();
        Provider::factory()->create([
            'display_name' => 'Inactive Demo Provider',
            'active' => false,
        ]);

        foreach (['super-admin', 'registration'] as $roleName) {
            $user = User::query()->where('email', $roleName.'@coor.test')->firstOrFail();

            $this->actingAs($user)->get(route('appointments.create'))
                ->assertOk()
                ->assertSee('value="'.$provider->id.'"', false)
                ->assertSee('No providers available for this department')
                ->assertDontSee('Inactive Demo Provider');

            $this->actingAs($user)->get(route('provider-unavailabilities.create'))
                ->assertOk()
                ->assertSee('name="provider_id"', false)
                ->assertSee('value="'.$provider->id.'"', false)
                ->assertDontSee('Inactive Demo Provider');
        }
    }

    public function test_demo_provider_and_slot_seeder_is_idempotent_and_assigns_doctor_roles(): void
    {
        $this->seed(DemoProvidersAndSlotsSeeder::class);
        $provider = Provider::query()->where('display_name', 'Dr. Gabriel Lim')->firstOrFail();
        $this->assertNotEmpty($provider->bio);
        $provider->update(['bio' => 'Keep this clinician-authored description.']);
        $providerCount = Provider::query()->count();
        $slotCount = AppointmentSlot::query()->count();

        $this->seed(DemoProvidersAndSlotsSeeder::class);

        $this->assertSame($providerCount, Provider::query()->count());
        $this->assertSame($slotCount, AppointmentSlot::query()->count());
        $this->assertSame('Keep this clinician-authored description.', $provider->fresh()->bio);
        $this->assertDatabaseHas('providers', [
            'display_name' => 'Dr. Gabriel Lim',
            'active' => true,
            'accepts_virtual' => true,
        ]);
        $this->assertTrue(User::query()->where('email', 'demo-provider-01@example.invalid')->firstOrFail()->hasRole('doctor'));
        $this->assertGreaterThan(0, AppointmentSlot::query()->where('status', AppointmentSlot::STATUS_AVAILABLE)->count());
    }

    public function test_patient_doctor_directory_uses_the_shared_filter_bar_style(): void
    {
        $patient = User::query()->where('email', 'patient@coor.test')->firstOrFail();

        $this->withoutMiddleware(EnsureCurrentPrivacyAcceptance::class)
            ->actingAs($patient)
            ->get(route('doctors.index'))
            ->assertOk()
            ->assertSee('hims-filter-bar hims-filter-bar--grid');
    }

    public function test_staff_provider_list_flags_missing_bios(): void
    {
        $provider = Provider::factory()->create(['bio' => null]);
        $nurse = User::factory()->create();
        $nurse->roles()->attach(\App\Models\Role::firstOrCreate(['name' => 'nurse'], ['label' => 'Nurse']));

        $this->actingAs($nurse)
            ->get(route('providers.profiles'))
            ->assertOk()
            ->assertSee('Missing bio')
            ->assertSee($provider->full_name);
    }

    public function test_provider_unavailability_access_excludes_nurse_on_web_and_api(): void
    {
        $provider = Provider::query()->where('active', true)->firstOrFail();
        $admin = User::factory()->create(['email' => 'availability-admin@example.test']);
        $admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        foreach ([
            'patient' => false,
            'doctor' => true,
            'nurse' => false,
            'registration' => true,
            'admin' => true,
            'super-admin' => true,
        ] as $roleName => $allowed) {
            $user = $roleName === 'admin'
                ? $admin
                : User::query()->whereHas('roles', fn ($query) => $query->where('name', $roleName))->firstOrFail();
            if ($roleName === 'patient') {
                $this->acceptRequiredPrivacyDocuments($user->patient, $user);
            }

            $payload = [
                'provider_id' => $roleName === 'doctor' ? $user->provider->id : $provider->id,
                'starts_at' => now()->addDay()->toDateTimeString(),
                'ends_at' => now()->addDay()->addHour()->toDateTimeString(),
                'reason' => \App\Models\ProviderUnavailability::REASON_SICK_LEAVE,
            ];
            $this->actingAs($user, 'web')->get(route('provider-unavailabilities.create'))
                ->assertStatus($allowed ? 200 : 403);
            $this->actingAs($user, 'web')->post(route('provider-unavailabilities.store'), $payload)
                ->assertStatus($allowed ? 302 : 403);
            $this->actingAs($user, 'sanctum')->postJson('/api/v1/provider-unavailabilities', $payload)
                ->assertStatus($allowed ? 201 : 403);

            $dashboard = $this->actingAs($user, 'web')->get('/dashboard')->assertOk();
            if ($roleName === 'doctor') {
                $this->assertSame(1, substr_count($dashboard->getContent(), 'Report unavailability'));
            } else {
                $dashboard->assertDontSee('Report unavailability');
            }
        }
    }
}
