<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PhilippineAddressDataset;
use Illuminate\Http\JsonResponse;

class PhilippineAddressDataController extends Controller
{
    public function __construct(protected PhilippineAddressDataset $dataset) {}

    public function regions(): JsonResponse
    {
        return $this->respond($this->dataset->regions());
    }

    public function provinces(string $region): JsonResponse
    {
        return $this->respond($this->dataset->provinces($region));
    }

    public function citiesByRegion(string $region): JsonResponse
    {
        return $this->respond($this->dataset->citiesByRegion($region));
    }

    public function citiesByProvince(string $province): JsonResponse
    {
        return $this->respond($this->dataset->citiesByProvince($province));
    }

    public function submunicipalities(string $city): JsonResponse
    {
        return $this->respond($this->dataset->submunicipalities($city));
    }

    public function barangays(string $parent): JsonResponse
    {
        return $this->respond($this->dataset->barangays($parent));
    }

    private function respond(array $rows): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }
}
