<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Department;
use App\Models\Patient;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $startsAt = now()->addDays(fake()->numberBetween(1, 60))->setTime(9, 0);

        return [
            'appointment_number' => 'APT-'.strtoupper(Str::random(12)),
            'patient_id' => Patient::factory(),
            'provider_id' => Provider::factory(),
            'department_id' => null,
            'appointment_type_id' => null,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
            'reason' => fake()->sentence(),
            'created_by' => null,
        ];
    }

    public function forDepartment(Department $department): static
    {
        return $this->state(['department_id' => $department->id]);
    }

    public function ofType(AppointmentType $type): static
    {
        return $this->state(['appointment_type_id' => $type->id]);
    }
}
