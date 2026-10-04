<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReadRateLimitAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_composite_indexes_cover_provider_patient_and_worklist_queries(): void
    {
        $this->assertIndex('appointments', ['provider_id', 'status', 'created_at']);
        $this->assertIndex('encounters', ['provider_id', 'status', 'created_at']);
        $this->assertIndex('encounters', ['patient_id', 'started_at']);
        $this->assertIndex('telehealth_sessions', ['status', 'start_time']);
        $this->assertIndex('triage_assessments', ['patient_id', 'triaged_at']);
        $this->assertIndex('triage_assessments', ['priority', 'triaged_at']);
    }

    public function test_patient_search_is_throttled_and_returns_retry_after(): void
    {
        $this->seed(HimsSeeder::class);
        $registration = User::where('email', 'registration@coor.test')->firstOrFail();

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->actingAs($registration)->getJson('/api/v1/patients/search/example')->assertOk();
        }

        $this->actingAs($registration)
            ->getJson('/api/v1/patients/search/example')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

    public function test_encounter_lookup_is_throttled_for_authenticated_clinicians(): void
    {
        $this->seed(HimsSeeder::class);
        $doctor = User::where('email', 'doctor@coor.test')->firstOrFail();

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->actingAs($doctor)->getJson('/api/v1/encounters')->assertOk();
        }

        $this->actingAs($doctor)
            ->getJson('/api/v1/encounters')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

    public function test_registration_lookup_displays_a_clear_throttle_message_instead_of_failing_silently(): void
    {
        $this->seed(HimsSeeder::class);
        $registration = User::where('email', 'registration@coor.test')->firstOrFail();

        $this->actingAs($registration)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('searchBlockedUntil')
            ->assertSee('Search limit reached. Please try again in');
    }

    private function assertIndex(string $table, array $columns): void
    {
        $indexes = Schema::getIndexes($table);

        $this->assertTrue(
            collect($indexes)->contains(fn (array $index) => $index['columns'] === $columns),
            "Expected composite index on {$table} (".implode(', ', $columns).').'
        );
    }
}
