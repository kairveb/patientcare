<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhilHealthNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $number = (string) $value;
        $digits = str_replace('-', '', $number);

        if (! preg_match('/^\d+(?:-\d+)*$/', $number)
            || strlen($digits) !== 12
            || substr_count($number, '-') > 2) {
            $fail('The :attribute field must contain 12 digits; dashes are optional.');
        }
    }
}
