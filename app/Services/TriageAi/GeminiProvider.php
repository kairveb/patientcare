<?php

namespace App\Services\TriageAi;

use App\Contracts\TriageAiProvider;
use Illuminate\Support\Facades\Http;

class GeminiProvider implements TriageAiProvider
{
    public function name(): string
    {
        return 'gemini';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.ai_triage.gemini.api_key'));
    }

    public function complete(string $systemPrompt, string $userPrompt): ?string
    {
        $model = rawurlencode((string) config('services.ai_triage.gemini.model'));
        $key = (string) config('services.ai_triage.gemini.api_key');
        $schema = TriageAiResponseSchema::gemini();

        $response = Http::withHeaders(['x-goog-api-key' => $key])
            ->timeout(max(1, (int) config('services.ai_triage.timeout', 10)))
            ->retry(max(1, (int) config('services.ai_triage.retries', 1) + 1), 150)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $schema,
                    'temperature' => 0.1,
                ],
            ]);

        $response->throw();

        $content = $response->json('candidates.0.content.parts.0.text');

        return is_string($content) ? $content : null;
    }
}
