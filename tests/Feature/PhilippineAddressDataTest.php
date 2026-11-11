<?php

namespace Tests\Feature;

use App\Services\PatientService;
use App\Services\PhilippineAddressDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class PhilippineAddressDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_regions_in_the_bundled_dataset_are_available(): void
    {
        $regions = $this->loadRows('/api/v1/address-data/philippines/regions');
        $regionCodes = array_column($regions, 'psgc_code');

        $this->assertGreaterThanOrEqual(17, count($regions));
        foreach ([
            '1300000000', '1400000000', '0100000000', '0200000000', '0300000000',
            '0400000000', '1700000000', '0500000000', '0600000000', '0700000000',
            '0800000000', '0900000000', '1000000000', '1100000000', '1200000000',
            '1600000000', '1900000000',
        ] as $regionCode) {
            $this->assertContains($regionCode, $regionCodes);
        }
    }

    public function test_address_data_responses_are_cacheable_with_the_existing_json_shape(): void
    {
        $response = $this->getJson('/api/v1/address-data/philippines/regions')
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => [['psgc_code', 'name']]]);
        $this->assertStringContainsString('max-age=86400', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('stale-while-revalidate=604800', $response->headers->get('Cache-Control'));
    }

    public function test_ncr_loads_manila_district_barangays_and_quezon_city_barangays(): void
    {
        $regionCode = '1300000000';
        $this->getJson("/api/v1/address-data/philippines/regions/{$regionCode}/provinces")
            ->assertOk()
            ->assertJsonPath('data', []);
        $cities = $this->loadRows("/api/v1/address-data/philippines/regions/{$regionCode}/cities");
        $manila = $this->findRow($cities, 'City of Manila');
        $quezonCity = $this->findRow($cities, 'Quezon City');

        $districts = $this->loadRows("/api/v1/address-data/philippines/cities/{$manila['psgc_code']}/sub-municipalities");
        $district = $districts[0];
        $this->loadRows("/api/v1/address-data/philippines/sub-municipalities/{$district['psgc_code']}/barangays");
        $this->loadRows("/api/v1/address-data/philippines/cities/{$quezonCity['psgc_code']}/barangays");
    }

    public function test_calabarzon_loads_cities_and_barangays_for_batangas_and_laguna(): void
    {
        $provinces = $this->loadRows('/api/v1/address-data/philippines/regions/0400000000/provinces');

        foreach (['Batangas', 'Laguna'] as $provinceName) {
            $province = $this->findRow($provinces, $provinceName);
            $cities = $this->loadRows("/api/v1/address-data/philippines/provinces/{$province['psgc_code']}/cities");
            $this->loadRows("/api/v1/address-data/philippines/cities/{$cities[0]['psgc_code']}/barangays");
        }
    }

    public function test_car_loads_provinces_cities_and_barangays(): void
    {
        $provinces = $this->loadRows('/api/v1/address-data/philippines/regions/1400000000/provinces');
        $province = $this->findRow($provinces, 'Benguet');
        $cities = $this->loadRows("/api/v1/address-data/philippines/provinces/{$province['psgc_code']}/cities");
        $this->loadRows("/api/v1/address-data/philippines/cities/{$cities[0]['psgc_code']}/barangays");

        $directCities = $this->loadRows('/api/v1/address-data/philippines/regions/1400000000/cities');
        $baguio = $this->findRow($directCities, 'City of Baguio');
        $this->loadRows("/api/v1/address-data/philippines/cities/{$baguio['psgc_code']}/barangays");
    }

    public function test_barmm_loads_provinces_cities_and_barangays(): void
    {
        $provinces = $this->loadRows('/api/v1/address-data/philippines/regions/1900000000/provinces');
        $province = $this->findRow($provinces, 'Lanao del Sur');
        $cities = $this->loadRows("/api/v1/address-data/philippines/provinces/{$province['psgc_code']}/cities");
        $this->loadRows("/api/v1/address-data/philippines/cities/{$cities[0]['psgc_code']}/barangays");
    }

    public function test_ncr_address_without_province_is_validated_saved_and_repopulated(): void
    {
        $dataset = app(PhilippineAddressDataset::class);
        $manila = $this->findRow($dataset->citiesByRegion('1300000000'), 'City of Manila');
        $district = $dataset->submunicipalities($manila['psgc_code'])[0];
        $barangay = $dataset->barangays($district['psgc_code'])[0];
        $selection = $dataset->validateSelection([
            'region' => 'National Capital Region (NCR)',
            'region_psgc_code' => '1300000000',
            'city' => $manila['name'],
            'city_psgc_code' => $manila['psgc_code'],
            'submunicipality' => $district['name'],
            'submunicipality_psgc_code' => $district['psgc_code'],
            'barangay' => $barangay['name'],
            'barangay_psgc_code' => $barangay['psgc_code'],
        ]);

        $this->assertArrayNotHasKey('province', $selection);
        $this->assertArrayNotHasKey('province_psgc_code', $selection);

        $patient = app(PatientService::class)->register([
            'first_name' => 'NCR',
            'last_name' => 'Address',
            'date_of_birth' => '1990-01-01',
            'sex' => 'Other',
            'address' => $selection,
        ]);
        $savedAddress = $patient->primaryAddress();
        $this->assertSame($selection['region'], $savedAddress->region);
        $this->assertSame($selection['region_psgc_code'], $savedAddress->region_psgc_code);
        $this->assertNull($savedAddress->province);
        $this->assertNull($savedAddress->province_psgc_code);
        $this->assertSame($selection['city'], $savedAddress->city);
        $this->assertSame($selection['city_psgc_code'], $savedAddress->city_psgc_code);
        $this->assertSame($selection['submunicipality'], $savedAddress->submunicipality);
        $this->assertSame($selection['submunicipality_psgc_code'], $savedAddress->submunicipality_psgc_code);
        $this->assertSame($selection['barangay'], $savedAddress->barangay);
        $this->assertSame($selection['barangay_psgc_code'], $savedAddress->barangay_psgc_code);

        $markup = Blade::render(
            '<x-philippine-address-fields
                :region-value="$region" :region-code-value="$regionCode"
                :city-value="$city" :city-code-value="$cityCode"
                :submunicipality-value="$district" :submunicipality-code-value="$districtCode"
                :barangay-value="$barangay" :barangay-code-value="$barangayCode"
            />',
            [
                'region' => $savedAddress->region,
                'regionCode' => $savedAddress->region_psgc_code,
                'city' => $savedAddress->city,
                'cityCode' => $savedAddress->city_psgc_code,
                'district' => $savedAddress->submunicipality,
                'districtCode' => $savedAddress->submunicipality_psgc_code,
                'barangay' => $savedAddress->barangay,
                'barangayCode' => $savedAddress->barangay_psgc_code,
            ]
        );

        $this->assertStringContainsString('1300000000', $markup);
        $this->assertStringContainsString($manila['psgc_code'], $markup);
        $this->assertStringContainsString($district['psgc_code'], $markup);
        $this->assertStringContainsString($barangay['psgc_code'], $markup);
    }

    private function loadRows(string $uri): array
    {
        $response = $this->getJson($uri)->assertOk()->assertJsonPath('success', true);
        $rows = $response->json('data');
        $this->assertNotEmpty($rows, "Expected address options from {$uri}.");

        return $rows;
    }

    private function findRow(array $rows, string $name): array
    {
        foreach ($rows as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        $this->fail("Expected {$name} in the address options.");
    }
}
