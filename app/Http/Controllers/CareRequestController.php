<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelCareRequestRequest;
use App\Http\Requests\DecideCareRequestRequest;
use App\Http\Requests\StoreCareRequestRequest;
use App\Http\Requests\StoreCareRequestResultRequest;
use App\Models\CareRequest;
use App\Models\Encounter;
use App\Services\CareRequestService;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CareRequestController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', CareRequest::class);
        $filters = $request->validate([
            'type' => ['nullable', 'in:lab,imaging,procedure'],
            'urgency' => ['nullable', 'in:routine,urgent'],
        ]);

        $query = CareRequest::query()
            ->with(['patient', 'encounter.provider', 'requestedBy'])
            ->where('status', CareRequest::STATUS_REQUESTED)
            ->when($filters['type'] ?? null, fn ($builder, $type) => $builder->where('type', $type))
            ->when($filters['urgency'] ?? null, fn ($builder, $urgency) => $builder->where('urgency', $urgency));

        if ($request->user()->hasRole('doctor')) {
            $query->where('requested_by', $request->user()->id);
        }

        return view('care-requests.index', [
            'requests' => $query->orderBy('created_at')->paginate(20)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function store(
        StoreCareRequestRequest $request,
        Encounter $encounter,
        CareRequestService $service
    ) {
        $careRequest = $service->create($encounter, $request->user(), $request->validated());

        return redirect()->route('care-requests.show', $careRequest)
            ->with('success', 'Outpatient request submitted for approval.');
    }

    public function show(CareRequest $careRequest, AuditLogService $audit)
    {
        $this->authorize('view', $careRequest);
        $careRequest->load([
            'patient',
            'encounter.provider',
            'appointment',
            'requestedBy',
            'decidedBy',
            'clinicalDocument',
            'statusHistories.user',
        ]);
        if (auth()->user()->hasAnyRole(['doctor', 'nurse', 'registration', 'admin', 'super-admin'])) {
            $audit->viewPatientClinicalData((int) $careRequest->patient_id, 'care_request', (int) $careRequest->id);
        }

        return view('care-requests.show', compact('careRequest'));
    }

    public function decide(
        DecideCareRequestRequest $request,
        CareRequest $careRequest,
        CareRequestService $service
    ) {
        $data = $request->validated();
        $careRequest = $service->decide(
            $careRequest,
            $request->user(),
            $data['decision'],
            $data['remarks'] ?? null
        );

        return redirect()->route('care-requests.show', $careRequest)
            ->with('success', 'Outpatient request decision saved.');
    }

    public function cancel(CancelCareRequestRequest $request, CareRequest $careRequest, CareRequestService $service)
    {
        $careRequest = $service->cancel($careRequest, $request->user(), $request->validated()['remarks'] ?? null);

        return redirect()->route('care-requests.show', $careRequest)
            ->with('success', 'Outpatient request cancelled.');
    }

    public function storeResult(
        StoreCareRequestResultRequest $request,
        CareRequest $careRequest,
        CareRequestService $service
    ) {
        $data = $request->validated();
        $careRequest = $service->attachResult(
            $careRequest,
            $request->user(),
            $data['result_text'],
            $request->file('result_file')
        );

        return redirect()->route('care-requests.show', $careRequest)
            ->with('success', 'Request result saved.');
    }

    public function resultFile(CareRequest $careRequest)
    {
        $this->authorize('viewResult', $careRequest);
        $document = $careRequest->clinicalDocument;
        abort_unless($document && Storage::exists($document->path), 404);

        return Storage::download($document->path, $document->name);
    }

    public function slip(CareRequest $careRequest)
    {
        $this->authorize('viewSlip', $careRequest);
        $careRequest->load(['patient', 'decidedBy', 'encounter']);

        return view('care-requests.slip', compact('careRequest'));
    }
}
