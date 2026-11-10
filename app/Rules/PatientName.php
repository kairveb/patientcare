<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PatientName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! preg_match("/^[\\pL\\pM\\s'’.-]+$/u", $value)) {
            $fail('The :attribute may only contain letters, spaces, apostrophes, hyphens, and periods.');
        }
    }
}
