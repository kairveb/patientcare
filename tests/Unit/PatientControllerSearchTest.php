<?php

namespace Tests\Unit;

use App\Http\Controllers\PatientController;
use App\Services\AuditLogService;
use App\Services\PatientConsentService;
use App\Services\PatientService;
use App\Services\PhilippineAddressDataset;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Database\Seeders\HimsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PatientControllerSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_paginated_items_from_the_service(): void
    {
        $this->seed(HimsSeeder::class);
        $user = \App\Models\User::where('email', 'registration@coor.test')->firstOrFail();

        $patientService = Mockery::mock(PatientService::class);
        $auditLogService = Mockery::mock(AuditLogService::class);

        $items = [
            ['id' => 1, 'first_name' => 'Ada'],
            ['id' => 2, 'first_name' => 'Grace'],
        ];

        $paginator = new LengthAwarePaginator($items, 2, 15, 1, ['path' => '/patients']);

        $patientService->shouldReceive('search')->once()->with($user, 'ada')->andReturn($paginator);

        $controller = new PatientController(
            $patientService,
            $auditLogService,
            Mockery::mock(PatientConsentService::class),
            app(PhilippineAddressDataset::class)
        );
        $request = new Request(['q' => 'ada']);
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);
        $response = $controller->search($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($items, $response->getData(true)['data']);
    }
}
