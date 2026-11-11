<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Notifications\AppointmentJourneyNotification;
use App\Notifications\EmergencyQueueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationPriorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_appointment_and_emergency_notifications_store_priority_and_module_metadata(): void
    {
        $user = User::factory()->create();
        $journey = new AppointmentJourneyNotification('Appointment confirmed', 'Your appointment is confirmed.');
        $emergency = new EmergencyQueueNotification('Critical case requires review.', 'Emergency');

        $this->assertSame('High', $journey->toDatabase($user)['priority']);
        $this->assertSame('ASS', $journey->toDatabase($user)['module']);
        $this->assertSame('Critical', $emergency->toDatabase($user)['priority']);
        $this->assertSame('EERTS', $emergency->toDatabase($user)['module']);
    }

    public function test_api_notification_list_can_filter_by_module_and_priority(): void
    {
        $user = User::factory()->create();
        Notification::sendNow($user, new EmergencyQueueNotification('ER alert', 'Emergency'), ['database']);
        Notification::sendNow($user, new AppointmentJourneyNotification('Appointment decision', 'Decision made.'), ['database']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications?module=EERTS&priority=Critical')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.data.module', 'EERTS')
            ->assertJsonPath('data.0.data.priority', 'Critical');
    }

    public function test_authenticated_staff_can_view_badged_and_filtered_web_notifications(): void
    {
        $role = Role::firstOrCreate(['name' => 'super-admin'], ['label' => 'Super Admin']);
        $user = User::factory()->create();
        $user->roles()->attach($role);
        Notification::sendNow($user, new EmergencyQueueNotification('ER alert', 'Emergency'), ['database']);

        $this->actingAs($user)
            ->get(route('notifications.index', ['module' => 'EERTS']))
            ->assertOk()
            ->assertSee('Critical')
            ->assertSee('EERTS')
            ->assertSee('ER alert');
    }
}
