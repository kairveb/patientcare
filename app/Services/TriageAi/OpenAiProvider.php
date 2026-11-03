<?php

namespace App\Services\TriageAi;

use App\Contracts\TriageAiProvider;
use Illuminate\Support\Facades\Http;

class OpenAiProvider implements TriageAiProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.ai_triage.openai.api_key'));
    }

    public function complete(string $systemPrompt, string $userPrompt): ?string
    {
        $schema = TriageAiResponseSchema::jsonSchema();
        $response = Http::withToken((string) config('services.ai_triage.openai.api_key'))
            ->timeout(max(1, (int) config('services.ai_triage.timeout', 10)))
            ->retry(max(1, (int) config('services.ai_triage.retries', 1) + 1), 150)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.ai_triage.openai.model'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'symptom_triage_result',
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
                'temperature' => 0.1,
            ]);

        $response->throw();

        $content = $response->json('choices.0.message.content');

        return is_string($content) ? $content : null;
    }
}
