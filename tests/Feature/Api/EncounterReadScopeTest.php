<?php

namespace Tests\Feature\Api;

use App\Models\Encounter;
use App\Models\EncounterNote;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EncounterReadScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_doctor_can_only_read_own_provider_encounters_and_notes(): void
    {
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $ownProvider = $doctor->provider()->firstOrFail();
        $otherDoctor = User::factory()->create();
        $otherDoctor->roles()->attach(Role::where('name', 'doctor')->firstOrFail());
        $otherProvider = Provider::create([
            'user_id' => $otherDoctor->id,
            'department_id' => $ownProvider->department_id,
            'display_name' => 'Dr. Other Encounter',
            'active' => true,
        ]);
        $patient = Patient::factory()->create();
        $own = $this->createEncounter($patient, $ownProvider, 'DOCTOR-OWN');
        $foreign = $this->createEncounter($patient, $otherProvider, 'DOCTOR-FOREIGN');
        $this->createNote($own, $doctor);
        $this->createNote($foreign, $otherDoctor);

        Sanctum::actingAs($doctor);

        $this->getJson('/api/v1/encounters')
            ->assertOk()
            ->assertJsonFragment(['id' => $own->id])
            ->assertJsonMissing(['id' => $foreign->id]);
        $this->getJson("/api/v1/encounters/{$own->id}")->assertOk();
        $this->getJson("/api/v1/encounters/{$foreign->id}")->assertForbidden();
        $this->getJson("/api/v1/encounters/{$own->id}/notes")->assertOk();
        $this->getJson("/api/v1/encounters/{$foreign->id}/notes")->assertForbidden();
    }

    public function test_nurse_can_only_read_emergency_encounters_and_notes(): void
    {
        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $provider = User::where('email', 'doctor@coor.test')->firstOrFail()->provider()->firstOrFail();
        $patient = Patient::factory()->create();
        $er = $this->createEncounter($patient, $provider, 'NURSE-ER', Encounter::TYPE_EMERGENCY);
        $opd = $this->createEncounter($patient, $provider, 'NURSE-OPD', Encounter::TYPE_OUTPATIENT);
        $this->createNote($er, $nurse);
        $this->createNote($opd, $nurse);

        Sanctum::actingAs($nurse);

        $this->getJson('/api/v1/encounters')
            ->assertOk()
            ->assertJsonFragment(['id' => $er->id])
            ->assertJsonMissing(['id' => $opd->id]);
        $this->getJson("/api/v1/encounters/{$er->id}")->assertOk();
        $this->getJson("/api/v1/encounters/{$opd->id}")->assertForbidden();
        $this->getJson("/api/v1/encounters/{$er->id}/notes")->assertOk();
        $this->getJson("/api/v1/encounters/{$opd->id}/notes")->assertForbidden();
    }

    public function test_patient_can_only_read_own_encounters_and_notes(): void
    {
        $patientUser = User::factory()->create();
        $patientUser->roles()->attach(Role::where('name', 'patient')->firstOrFail());
        $ownPatient = Patient::factory()->linkedTo($patientUser)->create();
        $otherPatient = Patient::factory()->create();
        $provider = Provider::firstOrFail();
        $own = $this->createEncounter($ownPatient, $provider, 'PATIENT-OWN');
        $foreign = $this->createEncounter($otherPatient, $provider, 'PATIENT-FOREIGN');
        $author = User::where('email', 'doctor@coor.test')->firstOrFail();
        $this->createNote($own, $author);
        $this->createNote($foreign, $author);

        Sanctum::actingAs($patientUser);

        $this->getJson('/api/v1/encounters')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
        $this->getJson("/api/v1/encounters/{$own->id}")->assertOk();
        $this->getJson("/api/v1/encounters/{$foreign->id}")->assertForbidden();
        $this->getJson("/api/v1/encounters/{$own->id}/notes")->assertOk();
        $this->getJson("/api/v1/encounters/{$foreign->id}/notes")->assertForbidden();
    }

    public function test_registration_is_forbidden_and_super_admin_can_read_encounters_and_notes(): void
    {
        $patient = Patient::factory()->create();
        $provider = Provider::firstOrFail();
        $encounter = $this->createEncounter($patient, $provider, 'ROLES');
        $author = User::where('email', 'doctor@coor.test')->firstOrFail();
        $this->createNote($encounter, $author);

        $registration = User::where('email', 'registration@coor.test')->firstOrFail();
        $registration->roles()->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([
            \App\Models\Permission::where('name', 'view-encounters')->value('id'),
        ]));
        Sanctum::actingAs($registration);
        $this->getJson('/api/v1/encounters')->assertForbidden();
        $this->getJson("/api/v1/encounters/{$encounter->id}")->assertForbidden();
        $this->getJson("/api/v1/encounters/{$encounter->id}/notes")->assertForbidden();

        Sanctum::actingAs(User::where('email', 'super-admin@coor.test')->firstOrFail());
        $this->getJson('/api/v1/encounters')->assertOk()->assertJsonFragment(['id' => $encounter->id]);
        $this->getJson("/api/v1/encounters/{$encounter->id}")->assertOk();
        $this->getJson("/api/v1/encounters/{$encounter->id}/notes")->assertOk();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/encounters')->assertForbidden();
    }

    private function createEncounter(
        Patient $patient,
        Provider $provider,
        string $number,
        string $type = Encounter::TYPE_OUTPATIENT
    ): Encounter {
        return Encounter::create([
            'encounter_number' => 'ENC-SCOPE-'.$number,
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'type' => $type,
            'started_at' => now(),
            'status' => Encounter::STATUS_OPEN,
        ]);
    }

    private function createNote(Encounter $encounter, User $author): EncounterNote
    {
        return EncounterNote::create([
            'encounter_id' => $encounter->id,
            'author_id' => $author->id,
            'body' => 'Confidential clinical note.',
        ]);
    }
}
