<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewDataSubjectRequest;
use App\Http\Requests\StoreDataSubjectRequest;
use App\Models\AuditLog;
use App\Models\DataSubjectRequest;
use App\Models\Patient;
use App\Services\DataSubjectRequestService;
use App\Services\DataSubjectRequestExport;
use App\Services\PatientConsentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivacyPortalController extends Controller
{
    public function index(Request $request, PatientConsentService $consents): View
    {
        $patient = $request->user()->patient()->firstOrFail();
        $history = $consents->history($patient);
        $requests = $patient->dataSubjectRequests()->latest()->paginate(10, ['*'], 'requests_page');
        $accessLogs = AuditLog::query()
            ->with('user.roles')
            ->where('resource_type', 'patient')
            ->where('resource_id', $patient->id)
            ->whereIn('action', ['VIEW_PATIENT', 'VIEW_PATIENT_CLINICAL_DATA'])
            ->whereHas('user.roles', fn ($query) => $query->whereIn('name', ['doctor', 'nurse', 'registration', 'admin', 'super-admin']))
            ->latest()
            ->paginate(15, ['*'], 'access_page');

        return view('patient-portal.privacy', compact('patient', 'history', 'requests', 'accessLogs'));
    }

    public function storeRequest(StoreDataSubjectRequest $request, DataSubjectRequestService $service)
    {
        $patient = $request->user()->patient()->firstOrFail();
        $data = $request->validated();
        $service->submit($patient->id, $data['type'], $data['note'], $request->user());

        return redirect()->route('patients.portal.privacy')->with('success', 'Your privacy request has been submitted.');
    }

    public function showRequest(Request $request, DataSubjectRequest $dataSubjectRequest): View
    {
        $this->authorize('view', $dataSubjectRequest);
        $dataSubjectRequest->load(['patient', 'handledBy.roles', 'statusHistories.user.roles']);

        return view('patient-portal.privacy-request', compact('dataSubjectRequest'));
    }

    public function queue(Request $request): View
    {
        Gate::authorize('viewAny', DataSubjectRequest::class);
        abort_if($request->user()->isPatient(), 403);
        $requests = DataSubjectRequest::query()
            ->with('patient')
            ->whereIn('status', [DataSubjectRequest::STATUS_SUBMITTED, DataSubjectRequest::STATUS_IN_REVIEW])
            ->oldest()
            ->paginate(20);

        return view('privacy-requests.queue', compact('requests'));
    }

    public function review(
        ReviewDataSubjectRequest $request,
        DataSubjectRequest $dataSubjectRequest,
        DataSubjectRequestService $service
    ) {
        $data = $request->validated();
        $service->review($dataSubjectRequest, $data['status'], $data['response_note'], $request->user());

        return back()->with('success', 'Privacy request status saved and the patient was notified.');
    }

    public function jsonCopy(Request $request, DataSubjectRequestExport $exports): StreamedResponse
    {
        $patient = $request->user()->patient()->firstOrFail();
        $payload = $exports->forPatient($patient);
        app(\App\Services\AuditLogService::class)->log('EXPORT_PATIENT_DATA', 'patient', $patient->id, 'SUCCESS', [
            'format' => 'json',
            'sections' => ['profile', 'consents', 'appointments', 'approvals', 'prescriptions'],
        ], $request->user()->id);

        return response()->streamDownload(
            static fn () => print json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'my-data-copy.json',
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public function printableCopy(Request $request, DataSubjectRequestExport $exports): View
    {
        $patient = $request->user()->patient()->firstOrFail();
        $payload = $exports->forPatient($patient);
        app(\App\Services\AuditLogService::class)->log('EXPORT_PATIENT_DATA', 'patient', $patient->id, 'SUCCESS', [
            'format' => 'html',
            'sections' => ['profile', 'consents', 'appointments', 'approvals', 'prescriptions'],
        ], $request->user()->id);

        return view('patient-portal.data-copy', ['data' => $payload]);
    }
}
