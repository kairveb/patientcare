<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PatientIdentifierNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)
            || ! preg_match('/^(?=.*[\pL\pN])[\pL\pN\pP\pS\s]+$/u', $value)) {
            $fail('The :attribute may contain letters, numbers, spaces, and punctuation only.');
        }
    }
}
