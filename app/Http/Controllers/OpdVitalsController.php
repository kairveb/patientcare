<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOpdVitalsRequest;
use App\Models\Appointment;
use App\Services\OpdVitalsService;
use Illuminate\Http\JsonResponse;

class OpdVitalsController extends Controller
{
    public function store(StoreOpdVitalsRequest $request, Appointment $appointment, OpdVitalsService $vitals): JsonResponse
    {
        $vital = $vitals->recordForAppointment($appointment, $request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'OPD intake vitals saved.',
            'data' => ['id' => $vital->id, 'bmi' => $vital->bmi],
        ]);
    }
}
