<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecordPatientConsentRequest;
use App\Models\Patient;
use App\Services\PatientConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PatientConsentController extends Controller
{
    public function __construct(protected PatientConsentService $consents) {}

    public function portalIndex(Request $request): View|JsonResponse
    {
        abort_unless(
            $request->user()->hasRole('patient')
                && $request->user()->hasPermission('portal-dashboard'),
            403
        );

        $patient = $request->user()->patient()->firstOrFail();

        return $this->respondWithConsents($request, $patient, true);
    }

    public function patientIndex(Patient $patient, Request $request): View|JsonResponse
    {
        abort_unless(
            $request->user()->hasRole('registration', 'nurse', 'doctor', 'super-admin'),
            403
        );
        $this->authorize('view', $patient);

        return $this->respondWithConsents($request, $patient, false);
    }

    public function portalStore(RecordPatientConsentRequest $request): RedirectResponse|JsonResponse
    {
        $patient = $request->user()->patient()->firstOrFail();
        $consent = $this->consents->record(
            $patient,
            $request->validated('type'),
            (bool) $request->validated('granted'),
            $request->user(),
            'self'
        );

        return $this->respondAfterRecord($request, $consent, route('patient-consents.portal'));
    }

    public function patientStore(
        RecordPatientConsentRequest $request,
        Patient $patient
    ): RedirectResponse|JsonResponse {
        $consent = $this->consents->record(
            $patient,
            $request->validated('type'),
            (bool) $request->validated('granted'),
            $request->user(),
            'staff'
        );

        return $this->respondAfterRecord($request, $consent, route('patients.consents.index', $patient));
    }

    private function respondWithConsents(Request $request, Patient $patient, bool $self): View|JsonResponse
    {
        $types = config('consents.types');
        $history = $this->consents->history($patient);
        $latest = [];
        foreach (array_keys($types) as $type) {
            $latest[$type] = $this->consents->latest($patient, $type);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'patient_id' => $patient->id,
                    'types' => $types,
                    'latest' => $latest,
                    'history' => $history,
                ],
            ]);
        }

        return view('patient-consents.index', [
            'patient' => $patient,
            'types' => $types,
            'latest' => $latest,
            'history' => $history,
            'self' => $self,
            'requiredType' => $request->query('required'),
        ]);
    }

    private function respondAfterRecord(
        RecordPatientConsentRequest $request,
        \App\Models\PatientConsent $consent,
        string $redirect
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $consent->granted ? 'Consent recorded.' : 'Consent withdrawal recorded.',
                'data' => $consent,
            ], 201);
        }

        return redirect($redirect)->with('success', $consent->granted ? 'Consent recorded.' : 'Consent withdrawal recorded.');
    }
}
