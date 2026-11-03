<?php

namespace Tests\Feature;

use App\Models\CareRequest;
use App\Models\Encounter;
use App\Models\Permission;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CareRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['patient', 'doctor', 'nurse', 'registration', 'super-admin'] as $name) {
            Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
        }

        $permissions = ['create-care-requests', 'approve-care-requests', 'view-care-requests'];
        foreach ($permissions as $name) {
            Permission::firstOrCreate(
                ['name' => $name],
                ['label' => ucwords(str_replace('-', ' ', $name))]
            );
        }
        $permissionIds = Permission::whereIn('name', $permissions)->pluck('id', 'name');
        foreach ([
            'doctor' => ['create-care-requests', 'view-care-requests'],
            'registration' => ['approve-care-requests', 'view-care-requests'],
            'nurse' => ['view-care-requests'],
            'patient' => ['view-care-requests'],
            'super-admin' => $permissions,
        ] as $roleName => $names) {
            Role::where('name', $roleName)->firstOrFail()
                ->permissions()->syncWithoutDetaching(
                    collect($names)->map(fn (string $name) => $permissionIds[$name])->all()
                );
        }
        Notification::fake();
    }

    public function test_doctor_can_create_an_outpatient_care_request_from_their_encounter(): void
    {
        [$doctor, $provider] = $this->doctor();
        $patient = Patient::factory()->create();
        $encounter = $this->encounter($patient, $provider);

        $this->actingAs($doctor)->post(route('encounters.care-requests.store', $encounter), [
            'type' => 'lab',
            'description' => 'Complete blood count for evaluation',
            'urgency' => 'routine',
        ])->assertRedirect();

        $careRequest = CareRequest::query()->firstOrFail();
        $this->get(route('care-requests.show', $careRequest))
            ->assertOk()
            ->assertSee('Complete blood count for evaluation');
        $viewEncounters = Permission::firstOrCreate(
            ['name' => 'view-encounters'],
            ['label' => 'View Encounters']
        );
        $doctor->roles()->first()->permissions()->syncWithoutDetaching([$viewEncounters->id]);
        $this->get(route('encounters.show', $encounter))
            ->assertOk()
            ->assertSee('Outpatient approval request')
            ->assertSee('Complete blood count for evaluation');
        $this->assertDatabaseHas('care_requests', [
            'patient_id' => $patient->id,
            'encounter_id' => $encounter->id,
            'requested_by' => $doctor->id,
            'type' => 'lab',
            'status' => CareRequest::STATUS_REQUESTED,
        ]);
        $this->assertDatabaseHas('care_request_status_histories', [
            'to_status' => CareRequest::STATUS_REQUESTED,
            'user_id' => $doctor->id,
        ]);
    }

    public function test_registration_can_approve_and_reject_with_required_rejection_remarks(): void
    {
        [, $provider] = $this->doctor();
        $registration = $this->userWithRole('registration');
        [$patientUser, $patient] = $this->patient();
        $firstRequest = $this->careRequest($this->encounter($patient, $provider));
        $secondRequest = $this->careRequest($this->encounter(Patient::factory()->create(), $provider));

        $this->actingAs($registration)
            ->get(route('care-requests.show', $firstRequest))
            ->assertOk()
            ->assertSee('Record a decision');
        $this->actingAs($registration)->post(route('care-requests.decide', $firstRequest), [
            'decision' => CareRequest::STATUS_APPROVED,
        ])->assertRedirect();

        $this->assertSame(CareRequest::STATUS_APPROVED, $firstRequest->fresh()->status);
        $this->assertNotEmpty($firstRequest->fresh()->approval_reference);
        Notification::assertSentTo($firstRequest->fresh()->requestedBy, \App\Notifications\CareRequestDecisionNotification::class);
        Notification::assertSentTo($patientUser, \App\Notifications\CareRequestDecisionNotification::class);

        $this->actingAs($registration)->post(route('care-requests.decide', $secondRequest), [
            'decision' => CareRequest::STATUS_REJECTED,
        ])->assertSessionHasErrors('remarks');
        $this->assertSame(CareRequest::STATUS_REQUESTED, $secondRequest->fresh()->status);

        $this->post(route('care-requests.decide', $secondRequest), [
            'decision' => CareRequest::STATUS_REJECTED,
            'remarks' => 'Please provide the supporting clinical information.',
        ])->assertRedirect();
        $this->assertSame(CareRequest::STATUS_REJECTED, $secondRequest->fresh()->status);
        $this->assertSame(
            'Please provide the supporting clinical information.',
            $secondRequest->fresh()->remarks
        );
    }

    public function test_patient_can_only_list_and_view_their_own_requests(): void
    {
        [, $provider] = $this->doctor();
        [$patientUser, $patient] = $this->patient();
        $ownRequest = $this->careRequest($this->encounter($patient, $provider), [
            'description' => 'Own patient laboratory request',
        ]);
        [, $otherPatient] = $this->patient();
        $otherRequest = $this->careRequest($this->encounter($otherPatient, $provider), [
            'description' => 'Another patient laboratory request',
        ]);

        $this->actingAs($patientUser)
            ->get(route('patients.portal.approvals'))
            ->assertOk()
            ->assertSee($ownRequest->description)
            ->assertDontSee($otherRequest->description);

        $this->get(route('patients.portal.approval', $ownRequest))->assertOk();
        $this->get(route('patients.portal.approval', $otherRequest))->assertForbidden();
    }

    public function test_closed_requests_cannot_be_decided_or_cancelled(): void
    {
        [, $provider] = $this->doctor();
        $doctor = Provider::query()->findOrFail($provider->id)->user;
        $registration = $this->userWithRole('registration');
        $request = $this->careRequest($this->encounter(Patient::factory()->create(), $provider), [
            'status' => CareRequest::STATUS_APPROVED,
            'approval_reference' => 'LOA-20261013-0001',
        ]);

        $this->actingAs($registration)->post(route('care-requests.decide', $request), [
            'decision' => CareRequest::STATUS_REJECTED,
            'remarks' => 'Not eligible.',
        ])->assertSessionHasErrors('status');

        $this->actingAs($doctor)->post(route('care-requests.cancel', $request), [
            'remarks' => 'Cancel this.',
        ])->assertSessionHasErrors('status');
        $this->assertSame(CareRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    public function test_each_approval_gets_a_unique_reference_number(): void
    {
        [, $provider] = $this->doctor();
        $registration = $this->userWithRole('registration');
        $first = $this->careRequest($this->encounter(Patient::factory()->create(), $provider));
        $second = $this->careRequest($this->encounter(Patient::factory()->create(), $provider));

        $this->actingAs($registration)->post(route('care-requests.decide', $first), [
            'decision' => CareRequest::STATUS_APPROVED,
        ])->assertRedirect();
        $this->post(route('care-requests.decide', $second), [
            'decision' => CareRequest::STATUS_APPROVED,
        ])->assertRedirect();

        $this->assertNotSame($first->fresh()->approval_reference, $second->fresh()->approval_reference);
        $this->assertDatabaseCount('care_request_reference_sequences', 1);
    }

    public function test_approval_slip_is_available_only_after_approval(): void
    {
        [, $provider] = $this->doctor();
        [$patientUser, $patient] = $this->patient();
        $pending = $this->careRequest($this->encounter($patient, $provider));
        $approved = $this->careRequest($this->encounter($patient, $provider), [
            'status' => CareRequest::STATUS_APPROVED,
            'approval_reference' => 'LOA-20261013-0001',
        ]);

        $this->actingAs($patientUser)->get(route('care-requests.slip', $pending))->assertForbidden();
        $this->get(route('care-requests.slip', $approved))
            ->assertOk()
            ->assertSee('Demo document. Not a real insurance authorization.')
            ->assertSee($approved->approval_reference);
    }

    public function test_doctor_without_create_permission_is_denied_from_submitting_a_request(): void
    {
        [, $provider] = $this->doctor();
        $doctorWithoutPermission = $this->userWithRole('doctor');
        $doctorWithoutPermission->roles()->first()->permissions()->detach(
            \App\Models\Permission::where('name', 'create-care-requests')->value('id')
        );
        $encounter = $this->encounter(Patient::factory()->create(), $provider);

        $this->actingAs($doctorWithoutPermission)->post(route('encounters.care-requests.store', $encounter), [
            'type' => 'imaging',
            'description' => 'Chest X-ray',
            'urgency' => 'urgent',
        ])->assertForbidden();
        $this->get(route('care-requests.index'))->assertOk();
    }

    private function doctor(): array
    {
        $doctor = $this->userWithRole('doctor');
        $provider = Provider::factory()->create(['user_id' => $doctor->id]);

        return [$doctor, $provider];
    }

    private function patient(): array
    {
        $user = $this->userWithRole('patient');
        $patient = Patient::factory()->linkedTo($user)->create();
        $this->acceptRequiredPrivacyDocuments($patient, $user);

        return [$user, $patient];
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());

        return $user;
    }

    private function encounter(Patient $patient, Provider $provider): Encounter
    {
        return Encounter::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'type' => Encounter::TYPE_OUTPATIENT,
        ]);
    }

    private function careRequest(Encounter $encounter, array $overrides = []): CareRequest
    {
        $data = [
            'patient_id' => $encounter->patient_id,
            'encounter_id' => $encounter->id,
            'appointment_id' => $encounter->appointment_id,
            'requested_by' => $encounter->provider->user_id,
            'type' => 'lab',
            'description' => 'Complete blood count requested',
            'urgency' => 'routine',
            'status' => CareRequest::STATUS_REQUESTED,
        ];

        return CareRequest::create(array_merge($data, $overrides));
    }
}
