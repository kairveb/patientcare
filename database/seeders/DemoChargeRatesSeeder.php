<?php

namespace Database\Seeders;

use App\Models\ChargeRate;
use App\Models\Specialty;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoChargeRatesSeeder extends Seeder
{
    private const RATES = [
        ['charge_code' => 'encounter:OUTPATIENT', 'category' => ChargeRate::CATEGORY_CONSULTATION, 'description' => 'Outpatient consultation', 'unit_amount' => 650.00],
        ['charge_code' => 'encounter:TELEHEALTH', 'category' => ChargeRate::CATEGORY_CONSULTATION, 'description' => 'Telehealth consultation', 'unit_amount' => 500.00],
        ['charge_code' => 'encounter:EMERGENCY', 'category' => ChargeRate::CATEGORY_EMERGENCY, 'description' => 'Emergency room assessment', 'unit_amount' => 1800.00],
        ['charge_code' => 'bed:default', 'category' => ChargeRate::CATEGORY_BED_DAILY, 'description' => 'Room and board, standard daily rate', 'unit_amount' => 1200.00],
        ['charge_code' => 'bed:general', 'category' => ChargeRate::CATEGORY_BED_DAILY, 'description' => 'Room and board, general ward per day', 'unit_amount' => 1200.00],
        ['charge_code' => 'bed:general-medicine', 'category' => ChargeRate::CATEGORY_BED_DAILY, 'description' => 'Room and board, general medicine ward per day', 'unit_amount' => 1200.00],
        ['charge_code' => 'bed:pediatric', 'category' => ChargeRate::CATEGORY_BED_DAILY, 'description' => 'Room and board, pediatrics ward per day', 'unit_amount' => 1400.00],
        ['charge_code' => 'bed:pediatrics', 'category' => ChargeRate::CATEGORY_BED_DAILY, 'description' => 'Room and board, pediatrics ward per day', 'unit_amount' => 1400.00],
        ['charge_code' => 'bed:icu', 'category' => ChargeRate::CATEGORY_BED_DAILY, 'description' => 'Room and board, intensive care per day', 'unit_amount' => 8500.00],
        ['charge_code' => 'bed:critical-care', 'category' => ChargeRate::CATEGORY_BED_DAILY, 'description' => 'Room and board, critical care per day', 'unit_amount' => 8500.00],
        ['charge_code' => 'bed:private', 'category' => ChargeRate::CATEGORY_BED_DAILY, 'description' => 'Room and board, private room per day', 'unit_amount' => 3500.00],
        ['charge_code' => 'lab:cbc', 'category' => ChargeRate::CATEGORY_LABORATORY, 'description' => 'Complete blood count', 'unit_amount' => 450.00],
        ['charge_code' => 'lab:urinalysis', 'category' => ChargeRate::CATEGORY_LABORATORY, 'description' => 'Routine urinalysis', 'unit_amount' => 250.00],
        ['charge_code' => 'lab:fasting-blood-sugar', 'category' => ChargeRate::CATEGORY_LABORATORY, 'description' => 'Fasting blood sugar', 'unit_amount' => 200.00],
        ['charge_code' => 'imaging:chest-xray', 'category' => ChargeRate::CATEGORY_IMAGING, 'description' => 'Chest X-ray, two views', 'unit_amount' => 900.00],
        ['charge_code' => 'imaging:ecg', 'category' => ChargeRate::CATEGORY_IMAGING, 'description' => '12-lead electrocardiogram', 'unit_amount' => 350.00],
        ['charge_code' => 'procedure:wound-dressing', 'category' => ChargeRate::CATEGORY_PROCEDURE, 'description' => 'Basic wound dressing', 'unit_amount' => 500.00],
        ['charge_code' => 'procedure:iv-insertion', 'category' => ChargeRate::CATEGORY_PROCEDURE, 'description' => 'Intravenous line insertion', 'unit_amount' => 300.00],
        ['charge_code' => 'prescription:amoxicillin', 'category' => ChargeRate::CATEGORY_PRESCRIPTION, 'description' => 'Amoxicillin, per unit', 'unit_amount' => 12.50],
        ['charge_code' => 'prescription:paracetamol', 'category' => ChargeRate::CATEGORY_PRESCRIPTION, 'description' => 'Paracetamol, per unit', 'unit_amount' => 3.50],
        ['charge_code' => 'prescription:ibuprofen', 'category' => ChargeRate::CATEGORY_PRESCRIPTION, 'description' => 'Ibuprofen, per unit', 'unit_amount' => 5.00],
        ['charge_code' => 'prescription:default', 'category' => ChargeRate::CATEGORY_PRESCRIPTION, 'description' => 'Default medication unit price', 'unit_amount' => 10.00],
        ['charge_code' => 'supply:sterile-gloves', 'category' => ChargeRate::CATEGORY_SUPPLY, 'description' => 'Sterile gloves, per pair', 'unit_amount' => 45.00],
        ['charge_code' => 'supply:syringe-5ml', 'category' => ChargeRate::CATEGORY_SUPPLY, 'description' => '5 mL disposable syringe', 'unit_amount' => 12.00],
    ];

    public function run(): void
    {
        foreach (Specialty::query()->orderBy('name')->get(['name']) as $specialty) {
            $name = trim($specialty->name);
            $code = 'consultation:'.Str::slug($name);
            if ($name === '' || $code === 'consultation:') {
                continue;
            }

            $amount = match (Str::lower($name)) {
                'cardiology' => 1200.00,
                'orthopedics' => 1000.00,
                'pediatrics', 'paediatrics' => 850.00,
                default => 850.00,
            };
            $this->seedRate([
                'charge_code' => $code,
                'category' => ChargeRate::CATEGORY_CONSULTATION,
                'description' => $name.' consultation',
                'unit_amount' => $amount,
            ]);
        }

        foreach (self::RATES as $rate) {
            $this->seedRate($rate);
        }
    }

    private function seedRate(array $rate): void
    {
        if (ChargeRate::query()->where('charge_code', $rate['charge_code'])->exists()) {
            return;
        }

        ChargeRate::updateOrCreate(
            ['charge_code' => $rate['charge_code']],
            [
                ...$rate,
                'currency' => 'PHP',
                'active' => true,
            ]
        );
    }
}
