<?php

namespace App\Contracts;

interface TriageAiProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    public function complete(string $systemPrompt, string $userPrompt): ?string;
}
