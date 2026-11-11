<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfidentialityAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_web_access_requires_and_records_confidentiality_acknowledgement(): void
    {
        $role = Role::firstOrCreate(['name' => 'nurse'], ['label' => 'Nurse']);
        $user = User::factory()->create(['last_activity_at' => now()]);
        $user->roles()->attach($role);
        app(\App\Services\AuditLogService::class)->log(
            'LOGIN_SUCCESS',
            'authentication',
            $user->id,
            'SUCCESS',
            null,
            $user->id
        );

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertRedirect(route('confidentiality.notice'));

        $this->get(route('confidentiality.notice'))
            ->assertOk()
            ->assertSee('Data Privacy Act of 2012 (RA 10173)')
            ->assertSee('not a legal determination');

        $this->post(route('confidentiality.acknowledge'), ['acknowledged' => '1'])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'CONFIDENTIALITY_NOTICE_ACKNOWLEDGED',
        ]);
        $this->get(route('notifications.index'))->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'CONFIDENTIALITY_NOTICE_ACKNOWLEDGED')->count());
    }

    public function test_staff_api_receives_precondition_and_can_acknowledge_without_a_migration(): void
    {
        $role = Role::firstOrCreate(['name' => 'doctor'], ['label' => 'Doctor']);
        $user = User::factory()->create(['last_activity_at' => now()]);
        $user->roles()->attach($role);
        app(\App\Services\AuditLogService::class)->log(
            'LOGIN_SUCCESS',
            'authentication',
            $user->id,
            'SUCCESS',
            null,
            $user->id
        );

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertStatus(428)
            ->assertJsonPath('code', 'confidentiality_acknowledgement_required');

        $this->postJson('/api/v1/auth/confidentiality-acknowledgment', ['acknowledged' => true])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/v1/notifications')->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'CONFIDENTIALITY_NOTICE_ACKNOWLEDGED',
        ]);
    }

    public function test_patient_role_is_not_required_to_acknowledge_staff_notice(): void
    {
        $role = Role::firstOrCreate(['name' => 'patient'], ['label' => 'Patient']);
        $patient = User::factory()->create();
        $patient->roles()->attach($role);

        $this->actingAs($patient)
            ->get(route('notifications.index'))
            ->assertOk();
        $this->assertDatabaseMissing('audit_logs', [
            'user_id' => $patient->id,
            'action' => 'CONFIDENTIALITY_NOTICE_ACKNOWLEDGED',
        ]);
    }
}
