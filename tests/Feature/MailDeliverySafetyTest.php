<?php

namespace Tests\Feature;

use App\Mail\TestMailMessage;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\TelehealthSession;
use App\Models\User;
use App\Notifications\AppointmentReminderNotification;
use Database\Seeders\HimsSeeder;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MailDeliverySafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_mail_test_command_sends_a_plain_text_mailable(): void
    {
        Mail::fake();

        $this->artisan('hims:mail:test', ['email' => 'demo@example.test'])
            ->expectsOutput('Mail test sent successfully.')
            ->assertExitCode(0);

        Mail::assertSent(TestMailMessage::class, fn (TestMailMessage $mail): bool => $mail->hasTo('demo@example.test'));
        $message = new TestMailMessage();
        $this->assertSame('emails.test-message', $message->build()->textView);
    }

    public function test_mail_test_command_reports_failure_without_logging_address_or_error_message(): void
    {
        Mail::shouldReceive('to')
            ->once()
            ->with('demo@example.test')
            ->andThrow(new \RuntimeException('fake-smtp-password-and-raw-body'));
        Log::spy();

        $this->artisan('hims:mail:test', ['email' => 'demo@example.test'])
            ->expectsOutput('Mail test failed. Check the sanitized application log entry.')
            ->assertExitCode(1);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            return $message === 'Email delivery failed.'
                && $context['operation'] === 'mail_test'
                && $context['recipient_hash'] !== 'demo@example.test'
                && ! str_contains(json_encode($context), 'fake-smtp-password-and-raw-body');
        })->once();
    }

    public function test_email_verification_smtp_failure_returns_a_friendly_message_and_logs_only_a_hash(): void
    {
        $user = User::factory()->unverified()->create();
        $this->mockNotificationFailure();
        Log::spy();

        $this->actingAs($user)->post(route('verification.send'))
            ->assertRedirect()
            ->assertSessionHas('warning', 'We could not send your verification email. Please try again later.');

        $this->assertSanitizedMailFailureLogged('email_verification', $user->email);
    }

    public function test_password_reset_smtp_failure_returns_a_friendly_message_and_logs_only_a_hash(): void
    {
        $user = User::factory()->create();
        $this->mockNotificationFailure();
        Log::spy();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertSanitizedMailFailureLogged('password_reset', $user->email);
    }

    public function test_telehealth_reminder_smtp_failure_is_reported_without_breaking_session(): void
    {
        [$patient, $session] = $this->telehealthSession();
        $user = $this->userWithRole('super-admin');
        Mail::shouldReceive('to')
            ->once()
            ->with($patient->email)
            ->andThrow(new \RuntimeException('fake-smtp-password-and-raw-body'));
        Log::spy();

        $this->actingAs($user)->postJson('/api/v1/telehealth/'.$session->id.'/reminder', [
            'channel' => 'email',
        ])
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The reminder could not be sent now. The telehealth session remains available; please try again later.');

        $this->assertSame(TelehealthSession::STATUS_ACTIVE, $session->fresh()->status);
        $this->assertSanitizedMailFailureLogged('telehealth_reminder', $patient->email);
    }

    public function test_appointment_reminder_notification_is_queued_by_contract(): void
    {
        $this->assertContains(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            class_implements(AppointmentReminderNotification::class)
        );
    }

    private function mockNotificationFailure(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('fake-smtp-password-and-raw-body'));
        $this->app->instance(Dispatcher::class, $dispatcher);
    }

    private function assertSanitizedMailFailureLogged(string $operation, string $email): void
    {
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($operation, $email): bool {
            return $message === 'Email delivery failed.'
                && $context['operation'] === $operation
                && is_string($context['recipient_hash'])
                && $context['recipient_hash'] !== $email
                && ! str_contains(json_encode($context), 'fake-smtp-password-and-raw-body');
        })->once();
    }

    private function telehealthSession(): array
    {
        $patient = Patient::factory()->create();
        $provider = Provider::create([
            'user_id' => User::factory()->create()->id,
            'display_name' => 'Dr. Reminder',
            'active' => true,
        ]);
        $appointment = Appointment::create([
            'appointment_number' => 'APT-MAIL-'.Str::upper(Str::random(8)),
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'appointment_type_id' => AppointmentType::where('telehealth', true)->firstOrFail()->id,
            'starts_at' => now(),
            'ends_at' => now()->addMinutes(30),
            'status' => Appointment::STATUS_IN_CONSULTATION,
        ]);
        $session = TelehealthSession::create([
            'appointment_id' => $appointment->id,
            'start_time' => now(),
            'duration' => 30,
            'status' => TelehealthSession::STATUS_ACTIVE,
        ]);

        return [$patient, $session];
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create([
            'email' => $roleName.'-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
