<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSymptomCheckRequest;
use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\SymptomCheck;
use App\Services\AiTriageService;
use App\Services\PatientConsentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SymptomCheckerController extends Controller
{
    public function __construct(protected PatientConsentService $consents) {}

    private const RED_FLAG_TERMS = [
        'chest pain',
        'difficulty breathing',
        'shortness of breath',
        'severe bleeding',
        'heavy bleeding',
        'stroke',
        'face droop',
        'slurred speech',
        'weakness on one side',
        'unresponsive',
        'loss of consciousness',
        'seizure',
        'cyanosis',
        'cardiac arrest',
        'respiratory arrest',
        'airway obstruction',
        'severe trauma',
    ];

    public function create()
    {
        $user = request()->user();
        $patients = $user->hasRole('patient')
            ? collect([$user->patient()->firstOrFail()])
            : Patient::query()->orderBy('last_name')->get();
        $selectedPatient = $user->hasRole('patient')
            ? $patients->first()
            : Patient::query()->find(request()->query('patient_id'));
        $aiConsentGranted = $selectedPatient
            ? $this->consents->hasCurrentGrant($selectedPatient, 'AI_ASSISTED')
            : null;

        return view('symptom-checker.create', compact('patients', 'selectedPatient', 'aiConsentGranted'));
    }

    public function store(StoreSymptomCheckRequest $request, AiTriageService $aiTriage): \Illuminate\Http\RedirectResponse|JsonResponse
    {
        $data = $request->validated();

        $user = $request->user();
        $patient = $user->hasRole('patient')
            ? $user->patient()->firstOrFail()
            : Patient::query()->findOrFail($data['patient_id']);
        if (! $this->consents->hasCurrentGrant($patient, 'AI_ASSISTED')) {
            $consentUrl = $user->hasRole('patient')
                ? route('patient-consents.portal', ['required' => 'AI_ASSISTED'])
                : route('patients.consents.index', ['patient' => $patient, 'required' => 'AI_ASSISTED']);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'code' => 'CONSENT_REQUIRED',
                    'consent_type' => 'AI_ASSISTED',
                    'consent_url' => $consentUrl,
                    'message' => 'Current AI-assisted care consent is required before running the symptom checker.',
                ], 403);
            }

            return redirect($consentUrl)->with('warning', 'AI-assisted care consent is required before running the symptom checker.');
        }
        $symptoms = array_values(array_filter(
            array_map('trim', preg_split('/[,;\n]+/', $data['symptoms'])),
            fn (string $symptom): bool => $symptom !== ''
        ));
        $vitals = array_filter($data['vitals'] ?? [], fn ($value): bool => $value !== null && $value !== '');
        foreach (['gcs', 'heart_rate', 'respiratory_rate'] as $vital) {
            if (array_key_exists($vital, $vitals)) {
                $vitals[$vital] = (int) $vitals[$vital];
            }
        }
        foreach (['spo2', 'blood_pressure', 'temperature'] as $vital) {
            if (array_key_exists($vital, $vitals)) {
                $vitals[$vital] = (float) $vitals[$vital];
            }
        }
        $analysis = $aiTriage->analyze([
            'chief_complaint' => $data['symptoms'],
            'symptoms' => $symptoms,
            'age' => (int) $data['age'],
            'vitals' => $vitals,
        ]);

        $text = mb_strtolower($data['symptoms']);
        $redFlags = array_values(array_filter(
            self::RED_FLAG_TERMS,
            fn (string $term): bool => str_contains($text, $term)
        ));
        if ((int) ($vitals['gcs'] ?? 16) <= 8) {
            $redFlags[] = 'GCS 8 or below';
        }

        $requiresEr = $redFlags !== [] || (int) $analysis['level'] <= AiTriageService::LEVEL_URGENT;
        $consultType = match (true) {
            $requiresEr => 'ER',
            (int) $analysis['level'] === AiTriageService::LEVEL_PROMPT => 'OPD',
            default => 'Virtual',
        };
        $department = match ($consultType) {
            'ER' => 'Emergency Department',
            'OPD' => 'Outpatient Department',
            default => 'Virtual Consultation',
        };
        $reasons = $analysis['reasons'];
        if ($redFlags !== []) {
            $reasons[] = 'Red-flag symptom(s) require emergency department assessment: '.implode(', ', $redFlags).'.';
        }

        $symptomCheck = SymptomCheck::create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'symptoms' => $symptoms,
            'age' => (int) $data['age'],
            'vitals' => $vitals,
            'urgency_level' => $analysis['priority'],
            'urgency_score' => (int) $analysis['level'],
            'ai_confidence' => $analysis['confidence'] ?? null,
            'ai_reasons' => $reasons,
            'red_flags' => $redFlags,
            'recommended_department' => $department,
            'recommended_consult_type' => $consultType,
            'requires_er' => $requiresEr,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => $symptomCheck,
                'next' => $requiresEr ? 'emergency-instructions' : 'continue-to-booking',
            ], 201);
        }

        return $requiresEr
            ? redirect()->route('symptom-checker.emergency', $symptomCheck)
            : redirect()->route('symptom-checker.show', $symptomCheck);
    }

    public function show(Request $request, SymptomCheck $symptomCheck): \Illuminate\View\View|JsonResponse
    {
        $this->authorizePatientAccess($request, $symptomCheck);
        abort_if($symptomCheck->requires_er, 404);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'data' => $symptomCheck]);
        }

        return view('symptom-checker.result', compact('symptomCheck'));
    }

    public function emergency(Request $request, SymptomCheck $symptomCheck): \Illuminate\View\View|JsonResponse
    {
        $this->authorizePatientAccess($request, $symptomCheck);
        abort_unless($symptomCheck->requires_er, 404);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'data' => $symptomCheck]);
        }

        return view('symptom-checker.emergency', compact('symptomCheck'));
    }

    private function authorizePatientAccess(Request $request, SymptomCheck $symptomCheck): void
    {
        $user = $request->user();
        if ($user->hasRole('patient') && $user->patient?->id !== $symptomCheck->patient_id) {
            abort(403);
        }
    }
}
