<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'mrn' => 'MRN-'.now()->format('Y').'-'.fake()->unique()->numerify('######'),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-90 years', '-1 year')->format('Y-m-d'),
            'sex' => fake()->randomElement(['Female', 'Male']),
            'civil_status' => 'Single',
            'nationality' => 'Filipino',
            'phone' => fake()->numerify('09#########'),
            'email' => fake()->unique()->safeEmail(),
            'verified' => true,
        ];
    }

    public function linkedTo(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }
}
