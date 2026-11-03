<?php

namespace App\Services\TriageAi;

class TriageAiResponseSchema
{
    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'urgency_level' => ['type' => 'string', 'enum' => ['emergency', 'urgent', 'routine', 'self_care']],
                'recommended_specialty' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'red_flags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'advice' => ['type' => 'array', 'items' => ['type' => 'string']],
                'follow_up_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => [
                'urgency_level',
                'recommended_specialty',
                'summary',
                'red_flags',
                'advice',
                'follow_up_questions',
            ],
        ];
    }

    public static function gemini(): array
    {
        $schema = self::jsonSchema();
        $schema['properties']['urgency_level']['type'] = 'STRING';
        $schema['properties']['urgency_level']['enum'] = ['emergency', 'urgent', 'routine', 'self_care'];
        $schema['properties']['recommended_specialty']['type'] = 'STRING';
        $schema['properties']['summary']['type'] = 'STRING';
        $schema['properties']['red_flags'] = ['type' => 'ARRAY', 'items' => ['type' => 'STRING']];
        $schema['properties']['advice'] = ['type' => 'ARRAY', 'items' => ['type' => 'STRING']];
        $schema['properties']['follow_up_questions'] = ['type' => 'ARRAY', 'items' => ['type' => 'STRING']];
        $schema['type'] = 'OBJECT';
        unset($schema['additionalProperties']);

        return $schema;
    }
}
