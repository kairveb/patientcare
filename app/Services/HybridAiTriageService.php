<?php

namespace App\Services;

use App\Contracts\TriageAiProvider;
use App\Models\Department;
use App\Models\Specialty;
use App\Services\TriageAi\GeminiProvider;
use App\Services\TriageAi\OpenAiProvider;
use App\Services\TriageAi\SpecialtyResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use JsonException;

class HybridAiTriageService
{
    private const RED_FLAG_TERMS = [
        'chest pain',
        'pain in chest',
        'sakit sa dibdib',
        'difficulty breathing',
        'shortness of breath',
        'cannot breathe',
        'can\'t breathe',
        'hirap huminga',
        'hinihingal',
        'severe bleeding',
        'heavy bleeding',
        'uncontrolled bleeding',
        'matinding pagdurugo',
        'stroke',
        'face droop',
        'slurred speech',
        'weakness on one side',
        'unresponsive',
        'unconscious',
        'loss of consciousness',
        'nawalan ng malay',
        'hindi magising',
        'seizure',
        'kombulsyon',
        'suicidal thoughts',
        'suicidal ideation',
        'want to kill myself',
        'want to die',
        'magpakamatay',
        'self-harm',
        'cyanosis',
        'cardiac arrest',
        'respiratory arrest',
        'airway obstruction',
        'severe trauma',
    ];

    public function __construct(
        private readonly AiTriageService $rules,
        private readonly SpecialtyResolver $specialties,
    ) {
    }

    public function analyze(
        array $input,
        int $remainingQuestions = 3,
        bool $finalTurn = false,
        array $identifiers = []
    ): array
    {
        if (! $this->isInScope($input)) {
            return $this->outOfScopeResult();
        }

        $ruleResult = $this->rules->analyze($input);
        $detectedRedFlags = $this->detectRedFlags($input);
        if ((int) ($input['vitals']['gcs'] ?? 16) <= 8) {
            $detectedRedFlags[] = 'GCS 8 or below';
        }
        if ((int) $ruleResult['level'] === AiTriageService::LEVEL_EMERGENCY && $detectedRedFlags === []) {
            $detectedRedFlags[] = 'Critical vital-sign threshold';
        }

        $provider = $this->configuredProvider();
        $aiResult = null;
        $fallbackReason = null;

        if ($provider === null) {
            $fallbackReason = $this->selectedProviderName() === 'rules'
                ? null
                : 'The configured AI provider is unavailable or has no API key.';
        } else {
            try {
                $content = $provider->complete(
                    $this->systemPrompt(),
                    $this->userPrompt($input, $remainingQuestions, $finalTurn, $identifiers)
                );
                $aiResult = $this->validateResponse($content, $remainingQuestions, $finalTurn);
                if ($aiResult === null) {
                    $fallbackReason = 'The AI response was not valid triage JSON.';
                } elseif ($aiResult['scope'] === 'out_of_scope') {
                    return $this->outOfScopeResult($provider->name());
                }
            } catch (ConnectionException|RequestException $exception) {
                $fallbackReason = 'The AI provider could not be reached.';
            } catch (JsonException $exception) {
                $fallbackReason = 'The AI response was not valid JSON.';
            }
        }

        $fallback = $aiResult === null;
        $selectedUrgency = $aiResult['urgency_level'] ?? $this->rulesUrgency((int) $ruleResult['level']);
        $ruleUrgency = $this->rulesUrgency((int) $ruleResult['level']);
        $ruleOverride = $aiResult !== null && $this->urgencyRank($ruleUrgency) < $this->urgencyRank($selectedUrgency);
        if ($ruleOverride) {
            $selectedUrgency = $ruleUrgency;
        }
        $allRedFlags = array_values(array_unique([
            ...$detectedRedFlags,
            ...($aiResult['red_flags'] ?? []),
        ]));

        if ($detectedRedFlags !== [] || (int) $ruleResult['level'] === AiTriageService::LEVEL_EMERGENCY) {
            $ruleOverride = $ruleOverride || ($aiResult !== null && $selectedUrgency !== 'emergency');
            $selectedUrgency = 'emergency';
        } elseif ($allRedFlags !== [] && $aiResult !== null) {
            $selectedUrgency = 'emergency';
        }

        $candidateSpecialty = $aiResult['recommended_specialty'] ?? 'General Medicine';
        $resolved = $this->specialties->resolve($candidateSpecialty);
        $needsEmergencyCare = in_array($selectedUrgency, ['emergency', 'urgent'], true);

        if ($fallback) {
            $aiResult = [
                'scope' => 'in_scope',
                'urgency_level' => $selectedUrgency,
                'severity_category' => in_array($selectedUrgency, ['emergency', 'urgent'], true)
                    ? 'Severe (critical)'
                    : 'Not Severe',
                'recommended_specialty' => $resolved['specialty'],
                'summary' => 'The rule-based symptom check suggests '.$resolved['department'].' for clinical review.',
                'red_flags' => $allRedFlags,
                'advice' => [
                    $needsEmergencyCare
                        ? 'Seek emergency care now. Do not wait for an online appointment.'
                        : 'Contact a healthcare professional for advice if symptoms worsen.',
                    'This is not a medical diagnosis.',
                ],
                'follow_up_questions' => [],
            ];
        } else {
            $aiResult['urgency_level'] = $selectedUrgency;
            $aiResult['red_flags'] = $allRedFlags;
            $aiResult['recommended_specialty'] = $resolved['specialty'];
        }

        if ($needsEmergencyCare) {
            $aiResult['follow_up_questions'] = [];
        }

        $aiResult['recommended_department'] = $resolved['department'];

        return [
            'provider' => $fallback ? 'rules' : $provider->name(),
            'fallback' => $fallback,
            'fallback_reason' => $fallbackReason,
            'rule_overrode_ai' => $ruleOverride,
            'urgency_level' => $selectedUrgency,
            'urgency_score' => $this->urgencyScore($selectedUrgency),
            'requires_er' => $needsEmergencyCare,
            'recommended_department' => $resolved['department'],
            'recommended_department_id' => $resolved['department_id'],
            'recommended_specialty' => $resolved['specialty'],
            'recommended_specialty_id' => $resolved['specialty_id'],
            'consult_type' => match ($selectedUrgency) {
                'emergency', 'urgent' => 'ER',
                'routine' => 'OPD',
                default => 'Virtual',
            },
            'result' => $aiResult,
            'fallback_message' => $fallbackReason === null
                ? 'Rule-based mode is active, so no external AI provider was called.'
                : 'AI service is unavailable or could not return a safe result, kaya the rule-based symptom check was used.',
            'reasons' => array_values(array_unique([
                $aiResult['summary'],
                ...$aiResult['advice'],
                ...($ruleOverride ? ['The rule-based safety layer upgraded this result to emergency.'] : []),
            ])),
            'confidence' => $fallback ? (int) $ruleResult['confidence'] : null,
        ];
    }

    public function selectedProviderName(): string
    {
        $name = strtolower((string) config('services.ai_triage.provider', 'rules'));

        return in_array($name, ['gemini', 'openai'], true) ? $name : 'rules';
    }

    private function configuredProvider(): ?TriageAiProvider
    {
        $provider = match ($this->selectedProviderName()) {
            'gemini' => app(GeminiProvider::class),
            'openai' => app(OpenAiProvider::class),
            default => null,
        };

        return $provider?->isConfigured() ? $provider : null;
    }

    private function systemPrompt(): string
    {
        $specialties = Specialty::query()->orderBy('name')->pluck('name')->all();
        $departments = Department::query()->where('active', true)->orderBy('name')->pluck('name')->all();

        return 'You are a cautious healthcare symptom-triage and appointment-booking assistant, not a general-purpose assistant. Only help with symptoms, triage, and booking. Never answer general knowledge, coding, schoolwork, entertainment, weather, politics, finance, travel, or other unrelated requests. If the request is unrelated, return scope "out_of_scope" and do not provide an answer. For health or booking requests return scope "in_scope". Do not diagnose, prescribe, or claim certainty. Recommend emergency care when red flags appear. Reply in the patient’s language (English, Tagalog, or Taglish). Keep advice short and plain. Always include exactly the sentence "This is not a medical diagnosis." in advice. Choose recommended_specialty only from this existing specialties/departments list: '
            .json_encode(array_values(array_unique([...$specialties, ...$departments])))
            .'. Return only JSON with scope, urgency_level, recommended_specialty, summary, red_flags, advice, and follow_up_questions. For out_of_scope, still use valid fields and provide only a short friendly redirect to symptom triage or booking. Ask no more than the follow-up question limit stated in the patient context. If final_turn is true, follow_up_questions must be empty.';
    }

    private function isInScope(array $input): bool
    {
        $text = mb_strtolower(implode(' ', [
            (string) ($input['chief_complaint'] ?? ''),
            implode(' ', array_map('strval', $input['symptoms'] ?? [])),
            ...array_map(fn (array $answer): string => (string) ($answer['answer'] ?? ''), $input['answers'] ?? []),
        ]));
        $offTopic = '/\b(?:write|generate|debug|review)\s+(?:some\s+)?(?:code|software|script)|\b(?:homework|essay|weather|politics|stock market|investment advice|tell me a joke|recipe|travel itinerary)\b/i';
        if (preg_match($offTopic, $text) === 1) {
            return false;
        }

        $healthOrBooking = '/\b(?:pain|ache|fever|cough|cold|nausea|vomit|diarrh(?:ea|oea)|rash|itch|dizzy|dizziness|breath|breathing|bleed|swelling|injury|symptom|sick|ill|medicine|medication|doctor|clinic|hospital|appointment|book|consultation|chest|headache|stomach|tummy|throat|cough|fatigue|weakness|my health|i feel|i have|i am feeling|sakit|lagnat|ubo|hilo|hirap huminga|pagsusuka|pagtatae)\b/i';

        return preg_match($healthOrBooking, $text) === 1;
    }

    private function outOfScopeResult(?string $provider = null): array
    {
        $message = 'I can only help with symptom triage and appointment booking. Please describe a health concern or continue to booking.';

        return [
            'scope' => 'out_of_scope',
            'provider' => $provider ?? 'rules',
            'fallback' => $provider === null,
            'fallback_reason' => null,
            'rule_overrode_ai' => false,
            'urgency_level' => 'self_care',
            'severity_category' => 'Not Severe',
            'urgency_score' => AiTriageService::LEVEL_ROUTINE,
            'requires_er' => false,
            'recommended_department' => 'General Medicine',
            'recommended_department_id' => null,
            'recommended_specialty' => 'General Medicine',
            'recommended_specialty_id' => null,
            'consult_type' => 'OPD',
            'result' => [
                'scope' => 'out_of_scope',
                'urgency_level' => 'self_care',
                'recommended_specialty' => 'General Medicine',
                'summary' => $message,
                'red_flags' => [],
                'advice' => [$message, 'This is not a medical diagnosis.'],
                'follow_up_questions' => [],
            ],
            'fallback_message' => $message,
            'reasons' => [],
            'confidence' => null,
        ];
    }

    private function userPrompt(array $input, int $remainingQuestions, bool $finalTurn, array $identifiers): string
    {
        $symptoms = array_map(
            fn (string $text): string => $this->maskIdentifiers($text, $identifiers),
            array_values(array_filter([
                implode(', ', array_map('strval', $input['symptoms'] ?? [])),
                ...array_map(
                    fn (array $answer): string => (string) ($answer['answer'] ?? ''),
                    $input['answers'] ?? []
                ),
            ], fn (string $text): bool => trim($text) !== ''))
        );
        $context = [
            'symptoms' => $symptoms,
            'follow_up_question_limit' => max(0, min(3, $remainingQuestions)),
            'final_turn' => $finalTurn,
        ];
        if (config('privacy.ai_send_age_sex', true)) {
            $age = isset($input['age']) ? (int) $input['age'] : null;
            $context['age_range'] = $age === null ? null : (intdiv(max(0, $age), 10) * 10).'-'.(intdiv(max(0, $age), 10) * 10 + 9);
            $context['sex'] = $input['sex'] ?? null;
        }

        return json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function maskIdentifiers(string $text, array $identifiers): string
    {
        usort($identifiers, fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));
        foreach ($identifiers as $identifier) {
            $identifier = trim((string) $identifier);
            if (mb_strlen($identifier) >= 3) {
                $text = str_ireplace($identifier, '[REDACTED]', $text);
            }
        }

        $patterns = [
            '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
            '/\b(?:MRN|PRC|PAC|APT|PATIENT\s*(?:ID|REF(?:ERENCE)?))\s*[:#-]?\s*[A-Z0-9][A-Z0-9-]{2,}\b/i',
            '/(?<!\w)(?:\+?63|0)[\d\s().-]{8,15}(?!\w)/',
            '/\b(?:my name is|i am|this is)\s+[A-Z][\pL\'-]+(?:\s+[A-Z][\pL\'-]+){0,3}\b/u',
            '/\b\d{1,5}\s+[\pL0-9 .\'-]{2,40}\s(?:Street|St\.?|Avenue|Ave\.?|Road|Rd\.?|Barangay|Brgy\.?)\b/iu',
            '/\b(?:address|located at|reside at|living at)\s*(?:is|:)?\s*[^,;\n]{5,}/iu',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '[REDACTED]', $text) ?? $text;
        }

        return $text;
    }

    private function validateResponse(?string $content, int $remainingQuestions, bool $finalTurn): ?array
    {
        if (! is_string($content) || $content === '') {
            return null;
        }

        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $exception;
        }

        $required = [
            'scope',
            'urgency_level',
            'recommended_specialty',
            'summary',
            'red_flags',
            'advice',
            'follow_up_questions',
        ];
        if (! is_array($data) || array_diff($required, array_keys($data)) !== [] || array_diff(array_keys($data), $required) !== []) {
            return null;
        }

        if (! in_array($data['scope'], ['in_scope', 'out_of_scope'], true)
            || ! in_array($data['urgency_level'], ['emergency', 'urgent', 'routine', 'self_care'], true)
            || ! $this->validText($data['recommended_specialty'], 100)
            || ! $this->validText($data['summary'], 500)
            || ! $this->validTextList($data['red_flags'], 10, 160)
            || ! $this->validTextList($data['advice'], 5, 300)
            || ! $this->validTextList($data['follow_up_questions'], 3, 240)) {
            return null;
        }

        $disclaimer = implode(' ', $data['advice']);
        if (! str_contains($disclaimer, 'This is not a medical diagnosis.')
            || count($data['follow_up_questions']) > $remainingQuestions
            || ($finalTurn && $data['follow_up_questions'] !== [])) {
            return null;
        }

        return [
            'scope' => $data['scope'],
            'urgency_level' => $data['urgency_level'],
            'recommended_specialty' => trim($data['recommended_specialty']),
            'summary' => trim($data['summary']),
            'red_flags' => array_map('trim', $data['red_flags']),
            'advice' => array_map('trim', $data['advice']),
            'follow_up_questions' => array_map('trim', $data['follow_up_questions']),
        ];
    }

    private function validText(mixed $value, int $maxLength): bool
    {
        return is_string($value)
            && trim($value) !== ''
            && mb_strlen($value) <= $maxLength;
    }

    private function validTextList(mixed $value, int $maxItems, int $maxLength): bool
    {
        if (! is_array($value) || count($value) > $maxItems) {
            return false;
        }

        foreach ($value as $item) {
            if (! $this->validText($item, $maxLength)) {
                return false;
            }
        }

        return true;
    }

    private function detectRedFlags(array $input): array
    {
        $text = mb_strtolower(implode(' ', [
            (string) ($input['chief_complaint'] ?? ''),
            implode(' ', array_map('strval', $input['symptoms'] ?? [])),
            ...array_map(
                fn (array $answer) => (string) ($answer['answer'] ?? ''),
                $input['answers'] ?? []
            ),
        ]));

        return array_values(array_filter(
            self::RED_FLAG_TERMS,
            fn (string $term) => str_contains($text, $term)
        ));
    }

    private function rulesUrgency(int $level): string
    {
        return match ($level) {
            AiTriageService::LEVEL_EMERGENCY => 'emergency',
            AiTriageService::LEVEL_URGENT => 'urgent',
            AiTriageService::LEVEL_PROMPT => 'routine',
            AiTriageService::LEVEL_NON_URGENT, AiTriageService::LEVEL_ROUTINE => 'self_care',
            default => 'routine',
        };
    }

    private function urgencyScore(string $urgency): int
    {
        return match ($urgency) {
            'emergency' => AiTriageService::LEVEL_EMERGENCY,
            'urgent' => AiTriageService::LEVEL_URGENT,
            'routine' => AiTriageService::LEVEL_PROMPT,
            default => AiTriageService::LEVEL_ROUTINE,
        };
    }

    private function urgencyRank(string $urgency): int
    {
        return match ($urgency) {
            'emergency' => 0,
            'urgent' => 1,
            'routine' => 2,
            default => 3,
        };
    }
}
