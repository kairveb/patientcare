<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PhilippineAddressDataset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PhilippineAddressDataController extends Controller
{
    public function __construct(protected PhilippineAddressDataset $dataset) {}

    public function regions(): JsonResponse
    {
        return $this->respond(fn (): array => $this->dataset->regions());
    }

    public function provinces(string $region): JsonResponse
    {
        return $this->respond(fn (): array => $this->dataset->provinces($region));
    }

    public function citiesByRegion(string $region): JsonResponse
    {
        return $this->respond(fn (): array => $this->dataset->citiesByRegion($region));
    }

    public function citiesByProvince(string $province): JsonResponse
    {
        return $this->respond(fn (): array => $this->dataset->citiesByProvince($province));
    }

    public function submunicipalities(string $city): JsonResponse
    {
        return $this->respond(fn (): array => $this->dataset->submunicipalities($city));
    }

    public function barangays(string $parent): JsonResponse
    {
        return $this->respond(fn (): array => $this->dataset->barangays($parent));
    }

    private function respond(callable $loadRows): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $loadRows(),
            ])->header('Cache-Control', 'public, max-age=86400, stale-while-revalidate=604800');
        } catch (RuntimeException $exception) {
            Log::error('Failed to load Philippine address dataset.', [
                'exception' => $exception,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Address data is temporarily unavailable. Please try again later.',
            ], 503);
        }
    }
}
