<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChargeRateManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HimsSeeder::class);
    }

    public function test_super_admin_can_save_supported_charge_codes_but_nurse_cannot(): void
    {
        $admin = User::query()->where('email', 'super-admin@coor.test')->firstOrFail();

        foreach (['OUTPATIENT', 'LAB-CBC', 'XRAY_CHEST'] as $code) {
            $this->actingAs($admin)->post(route('billing.rates.store'), [
                'charge_code' => $code,
                'category' => 'ENCOUNTER',
                'description' => 'QA rate '.$code,
                'unit_amount' => '125.00',
                'currency' => 'PHP',
                'active' => '1',
            ])->assertRedirect();

            $this->assertDatabaseHas('charge_rates', [
                'charge_code' => $code,
                'unit_amount' => '125.00',
            ]);
        }

        $nurse = User::query()->where('email', 'nurse@coor.test')->firstOrFail();
        $this->actingAs($nurse)->post(route('billing.rates.store'), [
            'charge_code' => 'NURSE_RATE_1',
            'category' => 'ENCOUNTER',
            'description' => 'Not authorized',
            'unit_amount' => '1.00',
            'currency' => 'PHP',
        ])->assertForbidden();
    }

    public function test_invalid_charge_code_error_is_rendered_once_and_form_matches_database_length(): void
    {
        $admin = User::query()->where('email', 'super-admin@coor.test')->firstOrFail();

        $response = $this->actingAs($admin)->from(route('billing.rates.index'))->followingRedirects()->post(route('billing.rates.store'), [
            'charge_code' => 'invalid code',
            'category' => 'ENCOUNTER',
            'description' => 'Invalid rate',
            'unit_amount' => '125.00',
            'currency' => 'PHP',
        ]);

        $response->assertOk()
            ->assertSee('maxlength="120"', false)
            ->assertSee('OUTPATIENT, LAB-CBC, XRAY_CHEST')
            ->assertSee('format is invalid');

        $this->assertSame(1, substr_count($response->getContent(), 'format is invalid'));
    }
}
