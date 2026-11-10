<?php

namespace App\Support;

use Illuminate\Validation\Validator;

class TriageDecisionRules
{
    public static function normalizeLevel(string $level): string
    {
        return match (strtolower(trim($level))) {
            'level 1', 'emergency', 'red' => 'Emergency',
            'level 2', 'urgent', 'yellow' => 'Urgent',
            'level 3', 'prompt', 'orange' => 'Prompt',
            'level 4', 'non-urgent', 'green' => 'Non-Urgent',
            'level 5', 'routine' => 'Routine',
            default => $level,
        };
    }

    public static function priorityCode(string $level): string
    {
        return match (self::normalizeLevel($level)) {
            'Emergency' => 'Level 1',
            'Urgent' => 'Level 2',
            'Prompt' => 'Level 3',
            'Non-Urgent' => 'Level 4',
            default => 'Level 5',
        };
    }

    public static function colorForLevel(string $level): string
    {
        return match (self::normalizeLevel($level)) {
            'Emergency' => 'red',
            'Urgent' => 'yellow',
            'Prompt' => 'orange',
            default => 'green',
        };
    }

    public static function validateOverride(
        Validator $validator,
        string $suggestedLevel,
        string $finalLevel,
        ?string $reason
    ): void {
        $suggestedLevel = self::normalizeLevel($suggestedLevel);
        $finalLevel = self::normalizeLevel($finalLevel);
        $reason = trim((string) $reason);

        if ($finalLevel !== $suggestedLevel && mb_strlen($reason) < 10) {
            $validator->errors()->add('override_reason', 'Explain the clinical reason for the override in at least 10 characters.');
        } elseif ($finalLevel === $suggestedLevel && $reason !== '') {
            $validator->errors()->add('override_reason', 'An override reason must be empty when accepting the AI suggestion.');
        }
    }
}
