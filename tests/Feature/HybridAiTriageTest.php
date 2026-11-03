<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\SymptomCheck;
use App\Models\User;
use App\Services\PatientConsentService;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class HybridAiTriageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
        config([
            'services.ai_triage.provider' => 'gemini',
            'services.ai_triage.gemini.api_key' => 'test-gemini-key',
            'services.ai_triage.gemini.model' => 'gemini-test',
            'services.ai_triage.retries' => 1,
            'services.ai_triage.timeout' => 1,
        ]);
    }

    public function test_valid_ai_response_is_validated_saved_and_audited(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse($this->validResult()), 200),
        ]);
        [$user, $patient] = $this->patientWithConsent();

        $response = $this->actingAs($user)->post(route('symptom-checker.store'), $this->payload($patient));

        $check = SymptomCheck::query()->firstOrFail();
        $response->assertRedirect(route('symptom-checker.show', $check));
        $this->assertSame('gemini', $check->ai_provider);
        $this->assertSame('routine', $check->ai_result['urgency_level']);
        $this->assertSame('Cardiology', $check->ai_result['recommended_specialty']);
        $this->assertFalse($check->rule_overrode_ai);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'SAVE_SYMPTOM_TRIAGE',
            'resource_id' => $check->id,
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'generativelanguage.googleapis.com')
            && str_contains($request->body(), 'chest discomfort'));

        $this->actingAs($user)
            ->get(route('symptom-checker.show', $check))
            ->assertOk()
            ->assertSee('AI-assisted suggestion. Final decision belongs to a healthcare professional.')
            ->assertSee('Book Appointment');

        $department = Department::query()->where('code', 'CAR')->firstOrFail();
        $provider = Provider::query()->where('display_name', 'Dr. Elena Santos')->firstOrFail();
        $this->actingAs($user)
            ->get(route('appointments.create', ['symptom_check_id' => $check->id]))
            ->assertOk()
            ->assertSee('value="'.$department->id.'" selected', false)
            ->assertSee('value="'.$provider->id.'" selected', false)
            ->assertSee('chest discomfort');

        $triageAudit = AuditLog::query()->where('action', 'RUN_SYMPTOM_TRIAGE')->firstOrFail();
        $this->assertArrayNotHasKey('symptoms', $triageAudit->metadata);
    }

    public function test_openai_provider_uses_structured_output_and_saves_provider_name(): void
    {
        config([
            'services.ai_triage.provider' => 'openai',
            'services.ai_triage.openai.api_key' => 'test-openai-key',
            'services.ai_triage.openai.model' => 'gpt-test',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode($this->validResult(), JSON_THROW_ON_ERROR)],
                ]],
            ], 200),
        ]);
        [$user, $patient] = $this->patientWithConsent();

        $this->actingAs($user)->post(route('symptom-checker.store'), $this->payload($patient))->assertRedirect();

        $check = SymptomCheck::query()->firstOrFail();
        $this->assertSame('openai', $check->ai_provider);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com')
            && $request['model'] === 'gpt-test'
            && $request['response_format']['type'] === 'json_schema'
            && $request->hasHeader('Authorization', 'Bearer test-openai-key'));
    }

    public function test_patient_identifiers_are_masked_from_external_ai_payload(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse($this->validResult()), 200),
        ]);
        [$user, $patient] = $this->patientWithConsent();
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
        $payload = $this->payload($patient);
        $payload['symptoms'] = 'Chest discomfort. My name is Mila Santos. Contact mila.santos@example.test or 09171234567. MRN-PRIVACY-778899. I live at 123 Sampaguita Street.';

        $this->actingAs($user)->post(route('symptom-checker.store'), $payload)->assertRedirect();

        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            $prompt = $body['contents'][0]['parts'][0]['text'];
            foreach ([
                'Mila Santos',
                'mila.santos@example.test',
                '09171234567',
                'MRN-PRIVACY-778899',
                '123 Sampaguita Street',
            ] as $identifier) {
                $this->assertStringNotContainsString($identifier, $prompt);
            }
            $this->assertStringContainsString('30-39', $prompt);
            $this->assertStringNotContainsString('"patient_id"', $prompt);

            return true;
        });
    }

    public function test_invalid_json_uses_the_rule_based_fallback(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '{invalid json']]]]],
            ], 200),
        ]);
        [$user, $patient] = $this->patientWithConsent();

        $this->actingAs($user)->post(route('symptom-checker.store'), $this->payload($patient))->assertRedirect();

        $check = SymptomCheck::query()->firstOrFail();
        $this->assertSame('rules', $check->ai_provider);
        $this->assertTrue($check->ai_result['fallback']);
        $this->assertSame('self_care', $check->ai_result['urgency_level']);
    }

    public function test_provider_timeout_uses_the_rule_based_fallback(): void
    {
        Http::fake(fn () => throw new ConnectionException('Simulated timeout'));
        [$user, $patient] = $this->patientWithConsent();

        $this->actingAs($user)->post(route('symptom-checker.store'), $this->payload($patient))->assertRedirect();

        $check = SymptomCheck::query()->firstOrFail();
        $this->assertSame('rules', $check->ai_provider);
        $this->assertTrue($check->ai_result['fallback']);
        $this->assertStringContainsString('kaya', $check->ai_result['fallback_message']);
    }

    public function test_rule_red_flag_overrides_a_less_urgent_ai_result(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse(
                $this->validResult([
                    'urgency_level' => 'self_care',
                    'follow_up_questions' => ['Can you describe the pain?'],
                ])
            ), 200),
        ]);
        [$user, $patient] = $this->patientWithConsent();
        $payload = $this->payload($patient);
        $payload['symptoms'] = 'chest pain';

        $response = $this->actingAs($user)->post(route('symptom-checker.store'), $payload);

        $check = SymptomCheck::query()->firstOrFail();
        $response->assertRedirect(route('symptom-checker.emergency', $check));
        $this->assertSame('emergency', $check->ai_result['urgency_level']);
        $this->assertTrue($check->rule_overrode_ai);
        $this->assertTrue($check->requires_er);
        $this->assertContains('chest pain', $check->red_flags);
        $this->assertSame([], $check->ai_result['follow_up_questions']);
    }

    public function test_missing_api_key_falls_back_without_making_an_external_request(): void
    {
        config(['services.ai_triage.provider' => 'gemini', 'services.ai_triage.gemini.api_key' => null]);
        Http::preventStrayRequests();
        [$user, $patient] = $this->patientWithConsent();

        $this->actingAs($user)->post(route('symptom-checker.store'), $this->payload($patient))->assertRedirect();

        $check = SymptomCheck::query()->firstOrFail();
        $this->assertSame('rules', $check->ai_provider);
        $this->assertTrue($check->ai_result['fallback']);
        Http::assertNothingSent();
    }

    public function test_triage_is_rate_limited_per_authenticated_user(): void
    {
        config(['services.ai_triage.provider' => 'rules']);
        Http::preventStrayRequests();
        [$user, $patient] = $this->patientWithConsent();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($user)
                ->post(route('symptom-checker.store'), $this->payload($patient))
                ->assertRedirect();
        }

        $this->actingAs($user)
            ->post(route('symptom-checker.store'), $this->payload($patient))
            ->assertTooManyRequests();

        $this->assertSame(5, SymptomCheck::query()->count());
        Http::assertNothingSent();
    }

    public function test_ai_can_ask_follow_up_questions_before_saving_final_result(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->geminiResponse($this->validResult([
                    'follow_up_questions' => ['How long have you had the cough?'],
                ])), 200)
                ->push($this->geminiResponse($this->validResult()), 200),
        ]);
        [$user, $patient] = $this->patientWithConsent();

        $followUp = $this->actingAs($user)
            ->post(route('symptom-checker.store'), $this->payload($patient))
            ->assertOk()
            ->assertSee('How long have you had the cough?');

        $this->assertSame(0, SymptomCheck::query()->count());
        $state = $followUp->viewData('state');

        $this->actingAs($user)
            ->post(route('symptom-checker.follow-up'), [
                'state' => $state,
                'answers' => ['Three days'],
            ])
            ->assertRedirect();

        $check = SymptomCheck::query()->firstOrFail();
        $this->assertSame('Three days', $check->ai_result['follow_up_answers'][0]['answer']);
        Http::assertSentCount(2);
    }

    private function patientWithConsent(): array
    {
        $user = User::factory()->create([
            'email' => 'patient-'.Str::uuid().'@example.test',
        ]);
        $user->roles()->attach(Role::query()->where('name', 'patient')->firstOrFail());
        $patient = Patient::factory()->create([
            'user_id' => $user->id,
            'sex' => 'Female',
        ]);
        app(PatientConsentService::class)->record($patient, 'DATA_PRIVACY', true, $user, 'self');
        app(PatientConsentService::class)->record($patient, 'TERMS_OF_USE', true, $user, 'self');
        app(PatientConsentService::class)->record($patient, 'AI_ASSISTED', true, $user, 'self');

        return [$user, $patient];
    }

    private function payload(Patient $patient): array
    {
        return [
            'patient_id' => $patient->id,
            'symptoms' => 'chest discomfort',
            'age' => 38,
            'vitals' => ['gcs' => 15, 'spo2' => 98],
        ];
    }

    private function validResult(array $overrides = []): array
    {
        return array_replace([
            'scope' => 'in_scope',
            'urgency_level' => 'routine',
            'recommended_specialty' => 'Cardiology',
            'summary' => 'The symptoms may benefit from a routine clinical review.',
            'red_flags' => [],
            'advice' => ['Arrange a clinical review if symptoms continue.', 'This is not a medical diagnosis.'],
            'follow_up_questions' => [],
        ], $overrides);
    }

    private function geminiResponse(array $result): array
    {
        return [
            'candidates' => [[
                'content' => [
                    'parts' => [['text' => json_encode($result, JSON_THROW_ON_ERROR)]],
                ],
            ]],
        ];
    }
}
