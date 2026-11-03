<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\CareRequest;
use App\Models\DataSubjectRequest;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PatientConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PrivacyAndDataRightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        foreach (['patient', 'doctor', 'nurse', 'registration', 'admin', 'super-admin'] as $name) {
            Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
        }
    }

    public function test_privacy_and_terms_pages_are_public(): void
    {
        $this->get(route('privacy.notice'))->assertOk()->assertSee('Data Protection Officer')->assertSee('Not configured');
        $this->get(route('privacy.terms'))->assertOk()->assertSee('Terms and Agreement');
        $this->assertGuest();
    }

    public function test_registration_requires_acceptance_and_records_current_versions(): void
    {
        Role::firstOrCreate(['name' => 'patient'], ['label' => 'Patient']);
        $payload = $this->registrationPayload();

        $this->post(route('register'), $payload)
            ->assertSessionHasErrors(['privacy_notice_accepted', 'terms_accepted']);
        $this->assertDatabaseCount('users', 0);

        $payload['privacy_notice_accepted'] = '1';
        $payload['terms_accepted'] = '1';
        $this->post(route('register'), $payload)->assertRedirect(route('dashboard'));

        $patient = auth()->user()->patient()->firstOrFail();
        $this->assertDatabaseHas('patient_consents', [
            'patient_id' => $patient->id,
            'type' => 'DATA_PRIVACY',
            'version' => config('privacy.notice_version'),
            'granted' => true,
            'channel' => 'self',
        ]);
        $this->assertDatabaseHas('patient_consents', [
            'patient_id' => $patient->id,
            'type' => 'TERMS_OF_USE',
            'version' => config('privacy.terms_version'),
            'granted' => true,
            'channel' => 'self',
        ]);
    }

    public function test_a_new_document_version_requires_acceptance_before_patient_can_continue(): void
    {
        $user = $this->patientUser();
        $patient = $user->patient;
        app(PatientConsentService::class)->record($patient, 'DATA_PRIVACY', true, $user, 'self');
        app(PatientConsentService::class)->record($patient, 'TERMS_OF_USE', true, $user, 'self');
        config([
            'privacy.notice_version' => '2.0',
            'privacy.terms_version' => '2.0',
            'consents.types.DATA_PRIVACY.version' => '2.0',
            'consents.types.TERMS_OF_USE.version' => '2.0',
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertRedirect(route('privacy.acceptance'));
        $this->get(route('privacy.acceptance'))->assertOk()->assertSee('2.0');
        $this->post(route('privacy.acceptance.store'), [
            'privacy_notice_accepted' => '1',
            'terms_accepted' => '1',
        ])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_withdrawn_ai_consent_blocks_symptom_checker(): void
    {
        $user = $this->patientUser();
        $patient = $user->patient;
        $this->recordRequiredConsents($user, $patient);
        app(PatientConsentService::class)->record($patient, 'AI_ASSISTED', true, $user, 'self');
        app(PatientConsentService::class)->record($patient, 'AI_ASSISTED', false, $user, 'self');

        $this->actingAs($user)->post(route('symptom-checker.store'), [
            'patient_id' => $patient->id,
            'age' => 40,
            'symptoms' => 'chest discomfort',
        ])->assertRedirect(route('patient-consents.portal', ['required' => 'AI_ASSISTED']));
        $this->assertDatabaseCount('symptom_checks', 0);
    }

    public function test_patient_can_submit_track_and_review_privacy_request_with_staff_policy(): void
    {
        $patientUser = $this->patientUser();
        $patient = $patientUser->patient;
        $this->actingAs($patientUser)->post(route('patients.portal.privacy.requests.store'), [
            'type' => DataSubjectRequest::TYPE_ACCESS_COPY,
            'note' => 'Please provide a copy of my records.',
        ])->assertRedirect();
        $request = DataSubjectRequest::query()->firstOrFail();
        $this->assertSame(DataSubjectRequest::STATUS_SUBMITTED, $request->status);
        $this->assertDatabaseHas('data_subject_request_status_histories', [
            'data_subject_request_id' => $request->id,
            'to_status' => DataSubjectRequest::STATUS_SUBMITTED,
        ]);

        $otherPatientUser = $this->patientUser();
        $this->actingAs($otherPatientUser)->get(route('privacy.requests.show', $request))->assertForbidden();

        $staff = $this->userWithRole('registration');
        $this->actingAs($staff)->get(route('privacy.requests.queue'))->assertOk()->assertSee($patient->full_name);
        $this->post(route('privacy.requests.review', $request), [
            'status' => DataSubjectRequest::STATUS_IN_REVIEW,
            'response_note' => 'Your request is being reviewed.',
        ])->assertRedirect();
        $request->refresh();
        $this->assertSame(DataSubjectRequest::STATUS_IN_REVIEW, $request->status);
        Notification::assertSentTo($patientUser, \App\Notifications\DataSubjectRequestNotification::class);
        $this->assertFalse($otherPatientUser->can('view', $request));
    }

    public function test_erasure_request_cannot_be_completed_without_retention_outcome(): void
    {
        $patientUser = $this->patientUser();
        $this->actingAs($patientUser)->post(route('patients.portal.privacy.requests.store'), [
            'type' => DataSubjectRequest::TYPE_ERASURE,
            'note' => 'Please erase my clinical record.',
        ])->assertRedirect();
        $request = DataSubjectRequest::query()->firstOrFail();
        $staff = $this->userWithRole('admin');

        $this->actingAs($staff)->post(route('privacy.requests.review', $request), [
            'status' => DataSubjectRequest::STATUS_COMPLETED,
            'response_note' => 'Request handled.',
        ])->assertSessionHasErrors('response_note');
        $this->assertSame(DataSubjectRequest::STATUS_SUBMITTED, $request->fresh()->status);
    }

    public function test_export_contains_only_the_authenticated_patients_data_and_is_logged(): void
    {
        $user = $this->patientUser();
        $patient = $user->patient;
        $otherUser = $this->patientUser();
        $otherPatient = $otherUser->patient;
        $ownAppointment = Appointment::factory()->create(['patient_id' => $patient->id]);
        $otherAppointment = Appointment::factory()->create(['patient_id' => $otherPatient->id]);
        $encounter = \App\Models\Encounter::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $ownAppointment->provider_id,
            'appointment_id' => $ownAppointment->id,
        ]);
        CareRequest::create([
            'patient_id' => $patient->id,
            'encounter_id' => $encounter->id,
            'requested_by' => $ownAppointment->provider->user_id,
            'type' => CareRequest::TYPE_LAB,
            'description' => 'Own patient CBC request',
            'urgency' => CareRequest::URGENCY_ROUTINE,
            'status' => CareRequest::STATUS_APPROVED,
            'approval_reference' => 'LOA-20261013-0001',
        ]);

        $response = $this->actingAs($user)->get(route('patients.portal.privacy.export.json'))->assertOk();
        $data = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($ownAppointment->appointment_number, $data['appointments'][0]['appointment_number']);
        $this->assertNotContains($otherAppointment->appointment_number, array_column($data['appointments'], 'appointment_number'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'EXPORT_PATIENT_DATA',
            'resource_type' => 'patient',
            'resource_id' => $patient->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_staff_patient_record_view_is_logged_and_patient_access_list_is_scoped(): void
    {
        $patientUser = $this->patientUser();
        $patient = $patientUser->patient;
        $otherUser = $this->patientUser();
        $otherPatient = $otherUser->patient;
        $staff = $this->userWithRole('registration');
        $this->grant($staff, 'view-patients');

        $this->actingAs($staff)->get(route('patients.show', $patient))->assertOk();
        $this->get(route('patients.show', $otherPatient))->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'VIEW_PATIENT',
            'resource_type' => 'patient',
            'resource_id' => $patient->id,
            'user_id' => $staff->id,
        ]);

        $response = $this->actingAs($patientUser)->get(route('patients.portal.privacy'))
            ->assertOk()
            ->assertSee('Staff');
        $this->assertSame(1, $response->viewData('accessLogs')->total());
    }

    public function test_symptom_text_is_masked_before_external_ai_request(): void
    {
        config([
            'services.ai_triage.provider' => 'gemini',
            'services.ai_triage.gemini.api_key' => 'unit-test-key',
            'services.ai_triage.gemini.model' => 'test-model',
        ]);
        $triageResult = [
            'scope' => 'in_scope',
            'urgency_level' => 'routine',
            'recommended_specialty' => 'General Medicine',
            'summary' => 'The symptoms can receive routine review.',
            'red_flags' => [],
            'advice' => ['Arrange review if symptoms persist.', 'This is not a medical diagnosis.'],
            'follow_up_questions' => [],
        ];
        \Illuminate\Support\Facades\Http::fake([
            'generativelanguage.googleapis.com/*' => \Illuminate\Support\Facades\Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode($triageResult, JSON_THROW_ON_ERROR)]]],
                ]],
            ], 200),
        ]);
        $user = $this->patientUser(['email' => 'patient.private@example.test']);
        $patient = $user->patient;
        $patient->update([
            'first_name' => 'Mila',
            'last_name' => 'Santos',
            'email' => 'mila.santos@example.test',
            'phone' => '09171234567',
            'mrn' => 'MRN-PRIVACY-778899',
        ]);
        $patient->addresses()->create([
            'line1' => '123 Sampaguita Street',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'postal_code' => '1100',
            'primary' => true,
        ]);
        $this->recordRequiredConsents($user, $patient);
        app(PatientConsentService::class)->record($patient, 'AI_ASSISTED', true, $user, 'self');
        $symptoms = 'Chest discomfort. My name is Mila Santos. Contact mila.santos@example.test or 09171234567. MRN-PRIVACY-778899. I live at 123 Sampaguita Street.';

        $this->actingAs($user)->post(route('symptom-checker.store'), [
            'patient_id' => $patient->id,
            'age' => 38,
            'symptoms' => $symptoms,
        ])->assertRedirect();

        \Illuminate\Support\Facades\Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            $prompt = $body['contents'][0]['parts'][0]['text'];
            $this->assertStringNotContainsString('Mila Santos', $prompt);
            $this->assertStringNotContainsString('mila.santos@example.test', $prompt);
            $this->assertStringNotContainsString('09171234567', $prompt);
            $this->assertStringNotContainsString('MRN-PRIVACY-778899', $prompt);
            $this->assertStringNotContainsString('123 Sampaguita Street', $prompt);
            $this->assertStringContainsString('30-39', $prompt);
            $this->assertStringNotContainsString('"patient_id"', $prompt);

            return true;
        });
    }

    private function registrationPayload(): array
    {
        return [
            'name' => 'Taylor Patient',
            'email' => 'taylor-'.uniqid().'@example.test',
            'password' => 'Capstone#2026',
            'password_confirmation' => 'Capstone#2026',
        ];
    }

    private function patientUser(array $attributes = []): User
    {
        $patientAttributes = $attributes['patient'] ?? [];
        unset($attributes['patient']);
        $user = User::factory()->create($attributes);
        $role = Role::where('name', 'patient')->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->id]);
        $patient = Patient::factory()->linkedTo($user)->create([
            'email' => $user->email,
            ...$patientAttributes,
        ]);
        $user->setRelation('patient', $patient);
        $this->recordRequiredConsents($user, $patient);

        return $user;
    }

    private function recordRequiredConsents(User $user, Patient $patient): void
    {
        app(PatientConsentService::class)->record($patient, 'DATA_PRIVACY', true, $user, 'self');
        app(PatientConsentService::class)->record($patient, 'TERMS_OF_USE', true, $user, 'self');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => $roleName], ['label' => ucfirst($roleName)]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function grant(User $user, string $permissionName): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => $permissionName],
            ['label' => ucwords(str_replace('-', ' ', $permissionName))]
        );
        $user->roles()->firstOrFail()->permissions()->syncWithoutDetaching([$permission->id]);
    }
}
