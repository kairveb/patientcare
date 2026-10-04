<?php

namespace Database\Factories;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Encounter>
 */
class EncounterFactory extends Factory
{
    protected $model = Encounter::class;

    public function definition(): array
    {
        return [
            'encounter_number' => 'ENC-'.strtoupper(Str::random(12)),
            'patient_id' => Patient::factory(),
            'provider_id' => Provider::factory(),
            'appointment_id' => null,
            'type' => Encounter::TYPE_OUTPATIENT,
            'started_at' => now(),
            'ended_at' => null,
            'chief_complaint' => fake()->sentence(),
            'assessment' => null,
            'plan' => null,
            'status' => Encounter::STATUS_OPEN,
        ];
    }
}
