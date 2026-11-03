<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_dashboard_omits_global_overview_metrics(): void
    {
        $this->seed(HimsSeeder::class);

        $user = User::where('email', 'doctor@coor.test')->firstOrFail();
        $token = $user->createToken('api-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'user' => ['id', 'name', 'email', 'role', 'roles'],
                'summary' => ['appointments_today', 'patients_today', 'follow_up_due'],
                'items' => ['appointments', 'encounters', 'queue', 'admissions', 'patient_count'],
            ],
        ]);

        $this->assertSame('doctor', $response->json('data.user.role'));
        $response->assertJsonMissingPath('data.overview');
        $response->assertJsonMissingPath('data.summary.er_patients');
        $response->assertJsonMissingPath('data.summary.occupied_beds');
    }

    public function test_nurse_dashboard_payload_contains_role_specific_queue_data(): void
    {
        $this->seed(HimsSeeder::class);

        $user = User::where('email', 'nurse@coor.test')->firstOrFail();
        $token = $user->createToken('api-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonPath('data.user.role', 'nurse');
        $response->assertJsonMissingPath('data.overview');
        $response->assertJsonMissingPath('data.summary.patients_today');
        $response->assertJsonMissingPath('data.summary.available_beds');
        $response->assertJsonStructure(['data' => ['summary' => ['triage_queue', 'admissions_pending']]]);
        $queue = $response->json('data.items.queue');
        $this->assertNotEmpty($queue);
        $this->assertIsString($queue[0]['title']);
    }

    public function test_doctor_dashboard_payload_includes_role_summary_counts(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(10));
        $this->seed(HimsSeeder::class);

        $user = User::where('email', 'doctor@coor.test')->firstOrFail();
        $token = $user->createToken('api-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonPath('data.user.role', 'doctor');
        $response->assertJsonMissingPath('data.overview');
        $response->assertJsonPath('data.summary.appointments_today', 1);
        $response->assertJsonPath('data.summary.patients_today', 0);
    }

    public function test_doctor_dashboard_worklists_and_counts_are_scoped_to_their_provider(): void
    {
        $this->seed(HimsSeeder::class);

        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $ownPatient = Patient::create([
            'mrn' => 'MRN-DASH-OWN-'. $doctor->id,
            'first_name' => 'Own',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-01-01',
            'sex' => 'Female',
        ]);
        $otherPatient = Patient::create([
            'mrn' => 'MRN-DASH-OTHER-'. $doctor->id,
            'first_name' => 'Other',
            'last_name' => 'Patient',
            'date_of_birth' => '1991-01-01',
            'sex' => 'Male',
        ]);
        $otherProvider = Provider::create([
            'user_id' => User::factory()->create()->id,
            'display_name' => 'Other Dashboard Provider',
            'active' => true,
        ]);

        $startsAt = today()->setTime(9, 0);
        $ownAppointment = Appointment::create([
            'appointment_number' => 'APT-DASH-SCOPED-'. $doctor->id,
            'patient_id' => $ownPatient->id,
            'provider_id' => $doctor->provider->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $otherAppointment = Appointment::create([
            'appointment_number' => 'APT-DASH-OTHER-SCOPED-'. $doctor->id,
            'patient_id' => $otherPatient->id,
            'provider_id' => $otherProvider->id,
            'starts_at' => $startsAt->copy()->addHour(),
            'ends_at' => $startsAt->copy()->addHour()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $ownPatient->encounters()->create([
            'encounter_number' => 'ENC-DASH-SCOPED-'. $doctor->id,
            'provider_id' => $doctor->provider->id,
            'type' => Encounter::TYPE_OUTPATIENT,
            'started_at' => now(),
            'follow_up_date' => today()->addDays(2),
            'status' => 'OPEN',
        ]);
        $otherPatient->encounters()->create([
            'encounter_number' => 'ENC-DASH-OTHER-SCOPED-'. $doctor->id,
            'provider_id' => $otherProvider->id,
            'type' => Encounter::TYPE_OUTPATIENT,
            'started_at' => now(),
            'follow_up_date' => today()->addDays(2),
            'status' => 'OPEN',
        ]);

        $response = $this->actingAs($doctor)->getJson('/api/v1/dashboard');
        $response->assertOk()
            ->assertJsonFragment(['id' => $ownAppointment->id])
            ->assertJsonMissing(['id' => $otherAppointment->id])
            ->assertJsonFragment(['title' => 'Own Patient'])
            ->assertJsonMissing(['title' => 'Other Patient']);
        $this->assertGreaterThanOrEqual(1, $response->json('data.summary.appointments_today'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.summary.patients_today'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.summary.follow_up_due'));
    }

    public function test_only_super_admin_receives_global_overview_and_operational_counts(): void
    {
        $this->seed(HimsSeeder::class);

        $admin = User::where('email', 'super-admin@coor.test')->firstOrFail();
        $response = $this->actingAs($admin)->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.user.role', 'super-admin')
            ->assertJsonStructure([
                'data' => [
                    'overview' => ['today_patients', 'today_appointments', 'er_patients', 'available_beds', 'occupied_beds', 'telehealth_appointments'],
                    'summary' => ['appointments_today', 'patients_today', 'er_patients', 'available_beds', 'occupied_beds', 'telehealth_appointments', 'follow_up_due'],
                ],
            ]);
    }

    public function test_web_staff_dashboards_do_not_render_or_receive_global_system_statistics(): void
    {
        $this->seed(HimsSeeder::class);

        $nurse = User::where('email', 'nurse@coor.test')->firstOrFail();
        $response = $this->actingAs($nurse)->get('/dashboard');

        $response->assertOk()
            ->assertSee('Triage queue')
            ->assertDontSee('Operational snapshot for today')
            ->assertDontSee('Patients registered today')
            ->assertDontSee('Bed occupancy')
            ->assertViewMissing('todayPatients')
            ->assertViewMissing('bedOccupancy')
            ->assertViewMissing('recentAuditLogs');
    }

    public function test_web_doctor_dashboard_receives_only_provider_scoped_worklists(): void
    {
        $this->seed(HimsSeeder::class);

        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();
        $ownPatient = Patient::create([
            'mrn' => 'MRN-WEB-DASH-OWN-'. $doctor->id,
            'first_name' => 'WebOwn',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-01-01',
            'sex' => 'Female',
        ]);
        $otherPatient = Patient::create([
            'mrn' => 'MRN-WEB-DASH-OTHER-'. $doctor->id,
            'first_name' => 'WebOther',
            'last_name' => 'Patient',
            'date_of_birth' => '1991-01-01',
            'sex' => 'Male',
        ]);
        $otherProvider = Provider::create([
            'user_id' => User::factory()->create()->id,
            'display_name' => 'Web Other Provider',
            'active' => true,
        ]);

        $ownAppointment = Appointment::create([
            'appointment_number' => 'APT-WEB-DASH-OWN-'. $doctor->id,
            'patient_id' => $ownPatient->id,
            'provider_id' => $doctor->provider->id,
            'starts_at' => today()->setTime(9, 0),
            'ends_at' => today()->setTime(9, 30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        $otherAppointment = Appointment::create([
            'appointment_number' => 'APT-WEB-DASH-OTHER-'. $doctor->id,
            'patient_id' => $otherPatient->id,
            'provider_id' => $otherProvider->id,
            'starts_at' => today()->setTime(10, 0),
            'ends_at' => today()->setTime(10, 30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $response = $this->actingAs($doctor)->get('/dashboard');
        $response->assertOk()
            ->assertSee('My schedule')
            ->assertSee('WebOwn Patient')
            ->assertDontSee('WebOther Patient')
            ->assertDontSee('Operational snapshot for today')
            ->assertViewMissing('todayPatients')
            ->assertViewMissing('bedOccupancy')
            ->assertViewHas('myAppointments', fn ($appointments) => $appointments->contains('patient_id', $ownPatient->id)
                && ! $appointments->contains('patient_id', $otherPatient->id)
                && ! $appointments->contains('id', $otherAppointment->id));
    }

    public function test_patient_dashboard_appointment_data_is_scoped_to_its_patient_profile(): void
    {
        $this->seed(HimsSeeder::class);

        $user = User::factory()->create();
        $patientRole = Role::where('name', 'patient')->firstOrFail();
        $user->roles()->attach($patientRole);

        $patient = Patient::create([
            'user_id' => $user->id,
            'mrn' => 'MRN-DASH-' . $user->id,
            'first_name' => 'Dashboard',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-01-01',
            'sex' => 'Female',
        ]);
        $this->acceptRequiredPrivacyDocuments($patient, $user);
        $otherPatient = Patient::create([
            'mrn' => 'MRN-DASH-OTHER-' . $user->id,
            'first_name' => 'Other',
            'last_name' => 'Patient',
            'date_of_birth' => '1991-01-01',
            'sex' => 'Male',
        ]);
        $provider = Provider::create([
            'user_id' => User::factory()->create()->id,
            'display_name' => 'Dashboard Test Provider',
            'active' => true,
        ]);

        $startsAt = today()->setTime(9, 0);
        Appointment::create([
            'appointment_number' => 'APT-DASH-' . $user->id,
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
        Appointment::create([
            'appointment_number' => 'APT-DASH-OTHER-' . $user->id,
            'patient_id' => $otherPatient->id,
            'provider_id' => $provider->id,
            'starts_at' => $startsAt->copy()->addHour(),
            'ends_at' => $startsAt->copy()->addHour()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $token = $user->createToken('api-token')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonPath('data.summary.appointments_today', 1);
        $response->assertJsonPath('data.summary.upcoming_appointments', 1);
        $response->assertJsonMissingPath('data.overview');
        $this->assertCount(1, $response->json('data.items.queue'));
    }
}
