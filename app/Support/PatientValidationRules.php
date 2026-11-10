<?php

namespace App\Support;

use App\Rules\PatientIdentifierNumber;
use App\Rules\PatientName;
use App\Rules\PhilHealthNumber;
use Illuminate\Validation\Rule;

class PatientValidationRules
{
    public static function name(string $presence = 'nullable'): array
    {
        return [$presence, 'string', 'max:255', new PatientName];
    }

    public static function sex(string $presence = 'nullable'): array
    {
        return [$presence, Rule::in(config('patient.sex_options'))];
    }

    public static function civilStatus(): array
    {
        return ['nullable', Rule::in(config('patient.civil_status_options'))];
    }

    public static function nationality(): array
    {
        return ['nullable', 'string', 'max:100'];
    }

    public static function identifiers(): array
    {
        return [
            'philhealth_number' => ['nullable', 'string', 'max:23', new PhilHealthNumber],
            'senior_id_number' => ['nullable', 'string', 'max:100', new PatientIdentifierNumber],
            'pwd_id_number' => ['nullable', 'string', 'max:100', new PatientIdentifierNumber],
            'hmo_member_id' => ['nullable', 'string', 'max:100', new PatientIdentifierNumber],
        ];
    }
}
