<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhilippineAddressPickerViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_same_picker_is_rendered_in_public_portal_profile_and_staff_forms(): void
    {
        $this->seed(HimsSeeder::class);

        $this->get('/pre-register')
            ->assertOk()
            ->assertSee('data-philippine-address-root', false)
            ->assertSee('data-address-select="region"', false)
            ->assertSee('/api/v1/address-data/philippines', false);

        $patient = User::where('email', 'patient@coor.test')->firstOrFail();
        $this->actingAs($patient)->get('/portal/pre-register')
            ->assertOk()
            ->assertSee('data-address-select="region"', false);
        $this->actingAs($patient)->get('/patients/profile')
            ->assertOk()
            ->assertSee('data-address-select="region"', false);

        $registration = User::where('email', 'registration@coor.test')->firstOrFail();
        $this->actingAs($registration)->get('/patients')
            ->assertOk()
            ->assertSee('data-address-select="region"', false);
    }
}
