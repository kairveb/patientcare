<?php

namespace App\Rules;

use App\Contracts\Icd10CodeValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Icd10Code implements ValidationRule
{
    public function __construct(protected Icd10CodeValidator $validator) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->validator->isValid($value)) {
            $fail('The :attribute must be a valid ICD-10 code format.');
        }
    }
}
