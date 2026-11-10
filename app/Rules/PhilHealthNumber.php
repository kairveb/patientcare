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

        if (! is_string($value)) {
            $fail('Please enter exactly 12 numbers. Spaces or dashes may be used for readability.');

            return;
        }

        $number = $value;
        $digits = str_replace(['-', ' '], '', $number);

        if (! preg_match('/^[0-9]+(?:[ -]?[0-9]+)*$/', $number)
            || strlen($digits) !== 12
        ) {
            $fail('Please enter exactly 12 numbers. Spaces or dashes may be used for readability.');
        }
    }
}
