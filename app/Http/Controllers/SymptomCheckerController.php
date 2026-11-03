<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSymptomCheckRequest;
use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\SymptomCheck;
use App\Services\AuditLogService;
use App\Services\HybridAiTriageService;
use App\Services\PatientConsentService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use JsonException;

class SymptomCheckerController extends Controller
{
    private const MAX_FOLLOW_UP_QUESTIONS = 3;

    public function __construct(protected PatientConsentService $consents)
    {
    }

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

    public function store(
        StoreSymptomCheckRequest $request,
        HybridAiTriageService $triage,
        AuditLogService $audit
    ): \Illuminate\Http\RedirectResponse|JsonResponse|\Illuminate\Contracts\View\View {
        $data = $request->validated();
        $user = $request->user();
        $patient = $user->hasRole('patient')
            ? $user->patient()->firstOrFail()
            : Patient::query()->findOrFail($data['patient_id']);

        if (! $this->consents->hasCurrentGrant($patient, 'AI_ASSISTED')) {
            $consentUrl = $this->consentUrl($user, $patient);
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
            fn (string $symptom) => $symptom !== ''
        ));
        $vitals = $this->normalizeVitals($data['vitals'] ?? []);
        $input = [
            'chief_complaint' => $data['symptoms'],
            'symptoms' => $symptoms,
            'age' => (int) $data['age'],
            'sex' => $patient->sex,
            'vitals' => $vitals,
            'answers' => [],
        ];

        $analysis = $triage->analyze($input, 3, false, $this->identifiersForMasking($patient));

        return $this->respondWithAnalysis($request, $patient, $analysis, $input, 0, $audit);
    }

    public function followUp(
        Request $request,
        HybridAiTriageService $triage,
        AuditLogService $audit
    ): \Illuminate\Http\RedirectResponse|\Illuminate\Contracts\View\View {
        $data = $request->validate([
            'state' => ['required', 'string', 'max:20000'],
            'answers' => ['required', 'array', 'min:1', 'max:3'],
            'answers.*' => ['required', 'string', 'min:1', 'max:1000'],
        ]);

        try {
            $state = json_decode(Crypt::decryptString($data['state']), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $exception) {
            return back()->withErrors(['state' => 'This follow-up session expired. Please start the symptom checker again.']);
        }

        if (! is_array($state)
            || ! isset($state['patient_id'], $state['input'], $state['questions'], $state['questions_asked'])
            || ! is_array($state['input'])
            || ! is_array($state['questions'])
            || count($state['questions']) !== count($data['answers'])) {
            return back()->withErrors(['state' => 'The follow-up answers did not match the questions. Please start again.']);
        }

        $patient = Patient::query()->findOrFail((int) $state['patient_id']);
        $user = $request->user();
        if ($user->hasRole('patient') && $user->patient?->id !== $patient->id) {
            abort(403);
        }
        if (! $this->consents->hasCurrentGrant($patient, 'AI_ASSISTED')) {
            return redirect($this->consentUrl($user, $patient))
                ->with('warning', 'AI-assisted care consent must remain active to continue this symptom check.');
        }

        $answers = [];
        foreach (array_values($data['answers']) as $index => $answer) {
            $answers[] = [
                'question' => (string) $state['questions'][$index],
                'answer' => trim($answer),
            ];
        }
        $input = $state['input'];
        $input['answers'] = [...($input['answers'] ?? []), ...$answers];
        $questionsAsked = (int) $state['questions_asked'];
        $remaining = max(0, self::MAX_FOLLOW_UP_QUESTIONS - $questionsAsked);
        $analysis = $triage->analyze(
            $input,
            $remaining,
            $remaining === 0,
            $this->identifiersForMasking($patient)
        );

        return $this->respondWithAnalysis($request, $patient, $analysis, $input, $questionsAsked, $audit);
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

    private function respondWithAnalysis(
        Request $request,
        Patient $patient,
        array $analysis,
        array $input,
        int $questionsAsked,
        AuditLogService $audit
    ): \Illuminate\Http\RedirectResponse|\Illuminate\Contracts\View\View|JsonResponse {
        if (($analysis['scope'] ?? 'in_scope') === 'out_of_scope') {
            $audit->log('REJECT_OUT_OF_SCOPE_TRIAGE', 'symptom_check', null, 'DENIED', [
                'source' => $analysis['provider'],
            ], $request->user()->id);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'code' => 'OUT_OF_SCOPE',
                    'message' => $analysis['fallback_message'],
                ], 422);
            }

            return view('symptom-checker.out-of-scope', ['message' => $analysis['fallback_message']]);
        }

        $questions = $analysis['result']['follow_up_questions'];
        $audit->log('RUN_SYMPTOM_TRIAGE', 'symptom_check', null, 'SUCCESS', [
            'provider' => $analysis['provider'],
            'fallback' => $analysis['fallback'],
            'rule_overrode_ai' => $analysis['rule_overrode_ai'],
            'urgency_level' => $analysis['urgency_level'],
            'stage' => $questions === [] ? 'completed' : 'follow_up',
            'follow_up_question_count' => count($questions),
        ]);

        if ($questions !== []) {
            $state = Crypt::encryptString(json_encode([
                'patient_id' => $patient->id,
                'input' => $input,
                'questions' => $questions,
                'questions_asked' => $questionsAsked + count($questions),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return view('symptom-checker.follow-up', [
                'questions' => $questions,
                'state' => $state,
            ]);
        }

        $urgencyLabel = match ($analysis['urgency_level']) {
            'emergency' => 'Emergency',
            'urgent' => 'Urgent',
            'routine' => 'Routine',
            default => 'Self-Care',
        };
        $result = [
            ...$analysis['result'],
            'fallback' => $analysis['fallback'],
            'fallback_message' => $analysis['fallback'] ? $analysis['fallback_message'] : null,
            'follow_up_answers' => $input['answers'] ?? [],
            'recommended_department_id' => $analysis['recommended_department_id'],
            'recommended_specialty_id' => $analysis['recommended_specialty_id'],
        ];
        $symptomCheck = SymptomCheck::create([
            'patient_id' => $patient->id,
            'created_by' => $request->user()->id,
            'symptoms' => $input['symptoms'],
            'age' => $input['age'],
            'vitals' => $input['vitals'],
            'urgency_level' => $urgencyLabel,
            'urgency_score' => $analysis['urgency_score'],
            'ai_confidence' => $analysis['confidence'],
            'ai_reasons' => $analysis['reasons'],
            'red_flags' => $analysis['result']['red_flags'],
            'recommended_department' => $analysis['recommended_department'],
            'recommended_consult_type' => $analysis['consult_type'],
            'requires_er' => $analysis['requires_er'],
            'ai_provider' => $analysis['provider'],
            'ai_result' => $result,
            'rule_overrode_ai' => $analysis['rule_overrode_ai'],
        ]);

        $audit->log('SAVE_SYMPTOM_TRIAGE', 'symptom_check', $symptomCheck->id, 'SUCCESS', [
            'provider' => $analysis['provider'],
            'fallback' => $analysis['fallback'],
            'rule_overrode_ai' => $analysis['rule_overrode_ai'],
            'urgency_level' => $analysis['urgency_level'],
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => $symptomCheck,
                'next' => $analysis['requires_er'] ? 'emergency-instructions' : 'continue-to-booking',
            ], 201);
        }

        return $analysis['requires_er']
            ? redirect()->route('symptom-checker.emergency', $symptomCheck)
            : redirect()->route('symptom-checker.show', $symptomCheck);
    }

    private function normalizeVitals(array $vitals): array
    {
        $vitals = array_filter($vitals, fn ($value) => $value !== null && $value !== '');
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

        return $vitals;
    }

    private function consentUrl($user, Patient $patient): string
    {
        return $user->hasRole('patient')
            ? route('patient-consents.portal', ['required' => 'AI_ASSISTED'])
            : route('patients.consents.index', ['patient' => $patient, 'required' => 'AI_ASSISTED']);
    }

    private function authorizePatientAccess(Request $request, SymptomCheck $symptomCheck): void
    {
        $user = $request->user();
        if ($user->hasRole('patient') && $user->patient?->id !== $symptomCheck->patient_id) {
            abort(403);
        }
    }

    private function identifiersForMasking(Patient $patient): array
    {
        return array_values(array_filter([
            $patient->full_name,
            $patient->first_name,
            $patient->middle_name,
            $patient->last_name,
            $patient->email,
            $patient->user?->email,
            $patient->phone,
            $patient->mrn,
            $patient->lookup_code,
            $patient->reference_code,
            ...$patient->addresses()->get(['line1', 'line2', 'city', 'province', 'postal_code'])
                ->flatMap(fn ($address) => [$address->line1, $address->line2, $address->city, $address->province, $address->postal_code])
                ->all(),
            ...$patient->preArrivalProfiles()->pluck('reference_code')->all(),
            ...$patient->appointments()->pluck('appointment_number')->all(),
            $patient->user?->name,
        ], fn ($identifier): bool => is_string($identifier) && trim($identifier) !== ''));
    }
}
