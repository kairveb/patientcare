<?php

namespace Tests\Feature;

use App\Models\ChargeRate;
use App\Models\Ward;
use Database\Seeders\DemoReferenceDataSeeder;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoReferenceDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_seeder_runs_idempotently_without_overwriting_existing_rates(): void
    {
        $this->seed(HimsSeeder::class);
        ChargeRate::create([
            'charge_code' => 'encounter:OUTPATIENT',
            'category' => 'ENCOUNTER',
            'description' => 'Production managed consultation',
            'unit_amount' => 999.00,
            'currency' => 'PHP',
            'active' => true,
        ]);

        $this->seed(DemoReferenceDataSeeder::class);
        $rateCount = ChargeRate::query()->count();
        $wardCount = Ward::query()->count();

        $this->assertDatabaseHas('charge_rates', [
            'charge_code' => 'encounter:OUTPATIENT',
            'description' => 'Production managed consultation',
            'unit_amount' => '999.00',
        ]);
        foreach ([
            'encounter:EMERGENCY',
            'consultation:cardiology',
            'bed:default',
            'bed:general-medicine',
            'bed:pediatrics',
            'bed:critical-care',
            'bed:private',
            'lab:cbc',
            'imaging:chest-xray',
            'procedure:wound-dressing',
            'prescription:default',
            'supply:sterile-gloves',
        ] as $code) {
            $this->assertDatabaseHas('charge_rates', ['charge_code' => $code, 'active' => true]);
        }

        $this->seed(DemoReferenceDataSeeder::class);

        $this->assertSame($rateCount, ChargeRate::query()->count());
        $this->assertSame($wardCount, Ward::query()->count());
    }
}
