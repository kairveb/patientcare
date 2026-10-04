<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\PatientAddress;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ReportInvalidPatientPsgcAddressesTest extends TestCase
{
    public function test_report_command_lists_nonmatching_addresses_without_modifying_them(): void
    {
        $validPatient = Patient::factory()->create();
        $validAddress = PatientAddress::create([
            'patient_id' => $validPatient->id,
            'line1' => '10 Valid Street',
            'region' => 'National Capital Region (NCR)',
            'region_psgc_code' => '1300000000',
            'city' => 'Quezon City',
            'city_psgc_code' => '1381300000',
            'barangay' => 'Bahay Toro',
            'barangay_psgc_code' => '1381300012',
            'primary' => true,
        ]);
        $patient = Patient::factory()->create();
        $address = PatientAddress::create([
            'patient_id' => $patient->id,
            'line1' => '12 Legacy Street',
            'region' => 'National Capital Region (NCR)',
            'region_psgc_code' => '1300000000',
            'city' => 'Quezon City',
            'city_psgc_code' => '1381300000',
            'barangay' => 'Not a PSGC barangay',
            'barangay_psgc_code' => '1381300012',
            'primary' => true,
        ]);

        $this->assertSame(0, Artisan::call('hims:patients:report-invalid-psgc-addresses'));
        $output = Artisan::output();
        $this->assertStringContainsString("address_id={$address->id}", $output);
        $this->assertStringContainsString('invalid_fields=barangay', $output);
        $this->assertStringNotContainsString("address_id={$validAddress->id}", $output);
        $this->assertStringContainsString('Reported 1 patient address(es)', $output);

        $address->refresh();
        $this->assertSame('Not a PSGC barangay', $address->barangay);
        $this->assertSame('1381300012', $address->barangay_psgc_code);
    }
}
