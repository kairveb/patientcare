<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApproveTelehealthSummaryRequest;
use App\Http\Requests\GenerateTelehealthSummaryRequest;
use App\Http\Requests\StoreTelehealthIntakeRequest;
use App\Http\Requests\UpdateTelehealthSummaryRequest;
use App\Models\TelehealthAiSummary;
use App\Models\TelehealthSession;
use App\Services\TelehealthIntakeService;
use App\Services\PatientConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TelehealthIntakeController extends Controller
{
    public function __construct(
        protected TelehealthIntakeService $intakes,
        protected PatientConsentService $consents
    ) {}

    public function create(TelehealthSession $session, Request $request): View
    {
        $this->assertOwnPatientSession($request, $session);
        $session->load('intake');
        $aiConsentGranted = $this->consents->hasCurrentGrant($session->appointment->patient, 'AI_ASSISTED');

        return view('patient-portal.telehealth-intake', compact('session', 'aiConsentGranted'));
    }

    public function store(StoreTelehealthIntakeRequest $request, TelehealthSession $session): RedirectResponse|JsonResponse
    {
        $patient = $session->appointment->patient;
        if (! $this->consents->hasCurrentGrant($patient, 'AI_ASSISTED')) {
            $consentUrl = route('patient-consents.portal', ['required' => 'AI_ASSISTED']);
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'code' => 'CONSENT_REQUIRED',
                    'consent_type' => 'AI_ASSISTED',
                    'consent_url' => $consentUrl,
                    'message' => 'Current AI-assisted care consent is required before submitting telehealth intake.',
                ], 403);
            }

            return redirect($consentUrl)->with('warning', 'AI-assisted care consent is required before submitting telehealth intake.');
        }

        $intake = $this->intakes->saveIntake($session, $request->user(), $request->validated());

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => $intake,
                'message' => $intake->is_emergency
                    ? 'The AI intake suggests emergency assessment. Follow the emergency instructions and contact the doctor.'
                    : 'Telehealth intake saved. The urgency result is decision support only.',
                'next' => $intake->is_emergency ? 'emergency-instructions' : 'telehealth-session',
                'next_url' => $intake->is_emergency
                    ? route('patients.portal.telehealth.emergency', $session)
                    : route('patients.portal.telehealth'),
            ], 201);
        }

        if ($intake->is_emergency) {
            return redirect()->route('patients.portal.telehealth.emergency', $session);
        }

        return redirect()->route('patients.portal.telehealth.intake', $session)
            ->with('success', 'Your telehealth intake was saved.');
    }

    public function emergencyInstructions(TelehealthSession $session, Request $request): View|JsonResponse
    {
        $this->assertOwnPatientSession($request, $session);
        $intake = $session->intake;
        abort_unless($intake?->is_emergency, 404);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Do not wait for a virtual appointment. Seek emergency care now or call 911 if you may be in immediate danger.',
                'data' => ['telehealth_session_id' => $session->id, 'is_emergency' => true],
            ]);
        }

        return view('patient-portal.telehealth-emergency', compact('session', 'intake'));
    }

    public function show(TelehealthSession $session, Request $request): JsonResponse
    {
        $this->authorizeIntakeRead($request, $session);
        $intake = $session->intake;
        abort_unless($intake, 404);

        return response()->json([
            'success' => true,
            'data' => $intake,
        ]);
    }

    public function showSummary(TelehealthSession $session, Request $request): JsonResponse
    {
        $this->authorizeSummaryRead($request, $session);
        $summary = $session->aiSummary;

        if ($request->user()->hasRole('patient')) {
            abort_unless($summary?->status === TelehealthAiSummary::STATUS_APPROVED, 404);
        }

        return response()->json(['success' => true, 'data' => $summary]);
    }

    public function generate(GenerateTelehealthSummaryRequest $request, TelehealthSession $session): RedirectResponse|JsonResponse
    {
        $summary = $this->intakes->generateDraft(
            $session,
            $request->user(),
            $request->validated('doctor_notes')
        );

        return $this->summaryResponse($request, $session, $summary, 'AI consultation summary generated as a draft.');
    }

    public function update(
        UpdateTelehealthSummaryRequest $request,
        TelehealthSession $session
    ): RedirectResponse|JsonResponse {
        $summary = $this->intakes->updateDraft(
            $session,
            $session->aiSummary()->firstOrFail(),
            $request->user(),
            $request->validated('summary_text')
        );

        return $this->summaryResponse($request, $session, $summary, 'AI consultation summary draft updated.');
    }

    public function approve(
        ApproveTelehealthSummaryRequest $request,
        TelehealthSession $session
    ): RedirectResponse|JsonResponse {
        $summary = $this->intakes->approveDraft(
            $session,
            $session->aiSummary()->firstOrFail(),
            $request->user()
        );

        return $this->summaryResponse($request, $session, $summary, 'Consultation summary approved and saved to the patient record.');
    }

    private function summaryResponse(
        Request $request,
        TelehealthSession $session,
        TelehealthAiSummary $summary,
        string $message
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message, 'data' => $summary]);
        }

        return redirect()->route('telehealth.show', $session)->with('success', $message);
    }

    private function authorizeIntakeRead(Request $request, TelehealthSession $session): void
    {
        $user = $request->user();
        $isOwnPatient = $user->hasRole('patient')
            && $user->hasPermission('portal-dashboard')
            && $user->patient?->id === $session->appointment?->patient_id;
        $isAssignedDoctor = $user->hasRole('doctor')
            && $user->hasPermission('view-telehealth')
            && $user->provider?->id === $session->appointment?->provider_id;

        $isNurse = $user->hasRole('nurse') && $user->hasPermission('view-telehealth');
        abort_unless($isOwnPatient || $isAssignedDoctor || $isNurse, 403);
    }

    private function authorizeSummaryRead(Request $request, TelehealthSession $session): void
    {
        $user = $request->user();
        $isOwnPatient = $user->hasRole('patient')
            && $user->hasPermission('portal-dashboard')
            && $user->patient?->id === $session->appointment?->patient_id;
        $isAssignedDoctor = $user->hasRole('doctor')
            && $user->hasPermission('view-telehealth')
            && $user->provider?->id === $session->appointment?->provider_id;

        $isNurse = $user->hasRole('nurse') && $user->hasPermission('view-telehealth');
        abort_unless($isOwnPatient || $isAssignedDoctor || $isNurse, 403);
    }

    private function assertOwnPatientSession(Request $request, TelehealthSession $session): void
    {
        abort_unless(
            $request->user()->hasRole('patient')
                && $request->user()->hasPermission('portal-dashboard')
                && $request->user()->patient?->id === $session->appointment?->patient_id,
            403
        );
    }
}
