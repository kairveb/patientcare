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
            || ! preg_match('/^[0-9]+(?:[ -]?[0-9]+)*$/', $value)) {
            $fail('Please enter numbers only. Spaces or dashes may be used for readability.');
        }
    }
}
