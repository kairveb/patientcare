<?php

namespace Tests\Feature\Api;

use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use App\Models\Waitlist;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WaitlistApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_registration_can_add_patient_to_waitlist(): void
    {
        $user = User::where('email', 'registration@coor.test')->first();
        $patient = Patient::first();
        $provider = Provider::first();
        $appointmentType = AppointmentType::first();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/waitlists', [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_type_id' => $appointmentType->id,
            'preferred_date' => now()->addDays(3)->toDateString(),
            'status' => 'WAITING',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'WAITING');

        $this->assertDatabaseHas('waitlists', ['patient_id' => $patient->id, 'provider_id' => $provider->id]);

        $this->postJson('/api/v1/waitlists', [
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'status' => Waitlist::STATUS_OFFERED,
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_patient_can_only_read_create_and_update_own_waitlist_entries(): void
    {
        $patientUser = User::factory()->create();
        $patientUser->roles()->attach(Role::where('name', 'patient')->firstOrFail());
        $ownPatient = Patient::factory()->linkedTo($patientUser)->create();
        $otherPatient = Patient::factory()->create();
        $provider = Provider::firstOrFail();
        $this->acceptRequiredPrivacyDocuments($ownPatient, $patientUser);

        $ownEntry = Waitlist::create([
            'patient_id' => $ownPatient->id,
            'provider_id' => $provider->id,
            'status' => Waitlist::STATUS_WAITING,
        ]);
        $otherEntry = Waitlist::create([
            'patient_id' => $otherPatient->id,
            'provider_id' => $provider->id,
            'status' => Waitlist::STATUS_WAITING,
        ]);

        Sanctum::actingAs($patientUser);

        $this->getJson('/api/v1/waitlists?patient_id='.$otherPatient->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownEntry->id);

        $this->patchJson("/api/v1/waitlists/{$otherEntry->id}", ['status' => Waitlist::STATUS_REMOVED])
            ->assertForbidden();
        $this->assertSame(Waitlist::STATUS_WAITING, $otherEntry->fresh()->status);

        $waitlistCount = Waitlist::count();
        $this->postJson('/api/v1/waitlists', [
            'patient_id' => $otherPatient->id,
            'provider_id' => $provider->id,
        ])->assertForbidden();
        $this->assertSame($waitlistCount, Waitlist::count());

        $this->postJson('/api/v1/waitlists', [
            'patient_id' => $ownPatient->id,
            'provider_id' => $provider->id,
        ])->assertCreated();
        $this->patchJson("/api/v1/waitlists/{$ownEntry->id}", ['status' => Waitlist::STATUS_OFFERED])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertSame(Waitlist::STATUS_WAITING, $ownEntry->fresh()->status);
        $this->patchJson("/api/v1/waitlists/{$ownEntry->id}", ['status' => Waitlist::STATUS_REMOVED])
            ->assertOk()
            ->assertJsonPath('data.status', Waitlist::STATUS_REMOVED);
        $this->assertSame(Waitlist::STATUS_REMOVED, $ownEntry->fresh()->status);
    }

    public function test_doctors_and_nurses_cannot_mutate_waitlists(): void
    {
        $patient = Patient::firstOrFail();
        $provider = Provider::firstOrFail();
        $entry = Waitlist::create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'status' => Waitlist::STATUS_WAITING,
        ]);

        foreach (['doctor', 'nurse'] as $roleName) {
            $user = User::whereHas('roles', fn ($query) => $query->where('name', $roleName))->firstOrFail();
            Sanctum::actingAs($user);

            $this->postJson('/api/v1/waitlists', [
                'patient_id' => $patient->id,
                'provider_id' => $provider->id,
            ])->assertForbidden();
            $this->patchJson("/api/v1/waitlists/{$entry->id}", ['status' => Waitlist::STATUS_REMOVED])
                ->assertForbidden();
        }

        $this->assertSame(Waitlist::STATUS_WAITING, $entry->fresh()->status);
    }
}
