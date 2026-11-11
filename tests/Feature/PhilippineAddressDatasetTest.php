<?php

namespace Tests\Feature;

use App\Services\PhilippineAddressDataset;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PhilippineAddressDatasetTest extends TestCase
{
    public function test_public_lazy_endpoints_return_the_psgc_hierarchy_without_a_giant_payload(): void
    {
        $regionsResponse = $this->getJson('/api/v1/address-data/philippines/regions');

        $regionsResponse->assertOk();
        $regions = $regionsResponse->json('data');
        $this->assertCount(18, $regions);
        $this->assertSame('1300000000', $regions[0]['psgc_code']);
        $this->assertSame('region', $regions[0]['geographic_level']);
        $this->assertArrayNotHasKey('children', $regions[0]);

        $this->getJson('/api/v1/address-data/philippines/regions/1300000000/provinces')
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => []]);

        $citiesResponse = $this->getJson('/api/v1/address-data/philippines/regions/1300000000/cities')
            ->assertOk();
        $manila = collect($citiesResponse->json('data'))->firstWhere('psgc_code', '1380600000');
        $this->assertNotNull($manila);
        $this->assertSame('city', $manila['geographic_level']);

        $submunicipalitiesResponse = $this->getJson('/api/v1/address-data/philippines/cities/1380600000/sub-municipalities')
            ->assertOk();
        $submunicipality = collect($submunicipalitiesResponse->json('data'))->firstWhere('name', 'Binondo');
        $this->assertNotNull($submunicipality);

        $barangaysResponse = $this->getJson('/api/v1/address-data/philippines/sub-municipalities/'.$submunicipality['psgc_code'].'/barangays')
            ->assertOk();
        $this->assertNotEmpty($barangaysResponse->json('data'));
        $this->assertSame($submunicipality['psgc_code'], $barangaysResponse->json('data.0.parent_psgc_code'));

        $provinces = $this->getJson('/api/v1/address-data/philippines/regions/0400000000/provinces')
            ->assertOk()
            ->json('data');
        $quezon = collect($provinces)->firstWhere('psgc_code', '0405600000');
        $this->assertNotNull($quezon);

        $provinceCities = $this->getJson('/api/v1/address-data/philippines/provinces/0405600000/cities')
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($provinceCities);
        $this->assertSame('0405600000', $provinceCities[0]['parent_psgc_code']);
    }

    public function test_public_address_endpoints_use_a_shared_production_safe_throttle(): void
    {
        $route = app('router')->getRoutes()->getByName('api.v1.address-data.regions');

        $this->assertNotNull($route);
        $this->assertContains('throttle:600,1', $route->middleware());
        $this->getJson('/api/v1/address-data/philippines/regions')->assertOk();
    }

    public function test_address_data_failure_is_logged_and_returns_a_clear_service_error(): void
    {
        $exception = new RuntimeException('Dataset file is unreadable.');
        $dataset = Mockery::mock(PhilippineAddressDataset::class);
        $dataset->shouldReceive('regions')->once()->andThrow($exception);
        $this->app->instance(PhilippineAddressDataset::class, $dataset);
        Log::spy();

        $this->getJson('/api/v1/address-data/philippines/regions')
            ->assertServiceUnavailable()
            ->assertJson([
                'success' => false,
                'message' => 'Address data is temporarily unavailable. Please try again later.',
            ]);

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Failed to load Philippine address dataset.', Mockery::on(
                fn (array $context): bool => $context['exception'] === $exception
            ));
    }

    public function test_address_selection_validation_supports_official_special_hierarchies(): void
    {
        $dataset = app(PhilippineAddressDataset::class);

        foreach ([
            '1381300012', // NCR city
            '1380601001', // Manila barangay through a sub-municipality
            '0405637001', // Province municipality
            '1430300001', // Highly urbanized city directly under its region
            '0990101002', // City of Isabela
            '1908703001', // City of Cotabato
        ] as $barangayCode) {
            $selection = $this->selectionForBarangay($dataset, $barangayCode);
            $validated = $dataset->validateSelection($selection);

            $this->assertSame($barangayCode, $validated['barangay_psgc_code']);
        }
    }

    public function test_address_selection_validation_rejects_mismatched_geographies(): void
    {
        $dataset = app(PhilippineAddressDataset::class);
        $selection = $this->selectionForBarangay($dataset, '1381300012');
        $selection['province'] = 'Quezon';
        $selection['province_psgc_code'] = '0405600000';

        try {
            $dataset->validateSelection($selection);
            $this->fail('A city paired with an unrelated province must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('province', $exception->errors());
        }
    }

    private function selectionForBarangay(PhilippineAddressDataset $dataset, string $barangayCode): array
    {
        $rows = [];
        $current = $dataset->row($barangayCode);
        while ($current['parent_psgc_code'] !== '') {
            $key = $current['geographic_level'] === 'municipality'
                ? 'city'
                : $current['geographic_level'];
            $rows[$key] = $current;
            $current = $dataset->row($current['parent_psgc_code']);
        }
        $rows[$current['geographic_level']] = $current;

        $selection = [];
        foreach (['region', 'province', 'city', 'submunicipality', 'barangay'] as $level) {
            if (! isset($rows[$level])) {
                continue;
            }
            $selection[$level] = $rows[$level]['name'];
            $selection[$level.'_psgc_code'] = $rows[$level]['psgc_code'];
        }

        return $selection;
    }
}
