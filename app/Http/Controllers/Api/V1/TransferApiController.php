<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Http\Requests\TransferBedRequest;
use App\Services\BedManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class TransferApiController extends Controller
{
    public function __construct(protected BedManagementService $bedManagement)
    {
    }

    /**
     * Transfer a patient to a new bed.
     */
    public function store(TransferBedRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();

        try {
            $admission = Admission::findOrFail($data['admission_id']);
            $transfer = $this->bedManagement->transfer(
                $admission,
                (int) $data['destination_bed_id'],
                $data['reason'] ?? null
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'The bed transfer could not be completed.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Patient transferred successfully.',
            'data' => $transfer->load('fromBed', 'toBed', 'admission'),
        ], 201);
    }
}
