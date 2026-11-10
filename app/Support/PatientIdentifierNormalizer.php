<?php

namespace App\Support;

class PatientIdentifierNormalizer
{
    public static function normalize(?string $value): ?string
    {
        return $value === null ? null : str_replace([' ', '-'], '', $value);
    }

    public static function normalizeFields(array $data): array
    {
        foreach (['philhealth_number', 'senior_id_number', 'pwd_id_number', 'hmo_member_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = self::normalize($data[$field]);
            }
        }

        return $data;
    }
}
