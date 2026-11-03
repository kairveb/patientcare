<?php

namespace Tests\Feature\Api;

use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Tests\TestCase;

class ApiPrivacyAcceptanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_patient_api_requires_current_documents_and_can_accept_them_without_being_locked_out(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'patient')->firstOrFail());
        Patient::factory()->linkedTo($user)->create();
        $token = $user->createToken('privacy-acceptance-test')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->withHeaders($headers)->getJson('/api/v1/auth/me')
            ->assertOk();
        $this->withHeaders($headers)->getJson('/api/v1/dashboard')
            ->assertStatus(428)
            ->assertJsonPath('code', 'privacy_acceptance_required')
            ->assertJsonPath('data.privacy_notice_url', route('privacy.notice'))
            ->assertJsonPath('data.terms_url', route('privacy.terms'))
            ->assertJsonPath('data.acceptance_url', route('api.v1.privacy.acceptance'));

        $this->withHeaders($headers)->postJson('/api/v1/privacy/acceptance', [
            'privacy_notice_accepted' => true,
            'terms_accepted' => true,
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeaders($headers)->getJson('/api/v1/dashboard')
            ->assertOk();
    }

    public function test_api_privacy_acceptance_requires_both_current_documents(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'patient')->firstOrFail());
        Patient::factory()->linkedTo($user)->create();
        $token = $user->createToken('privacy-acceptance-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/privacy/acceptance', [
                'privacy_notice_accepted' => true,
                'terms_accepted' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('terms_accepted');
    }
}
