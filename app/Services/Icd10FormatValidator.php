<?php

namespace App\Services;

use App\Contracts\Icd10CodeValidator;

class Icd10FormatValidator implements Icd10CodeValidator
{
    public function isValid(string $code): bool
    {
        return preg_match('/^[A-Z]\d{2}(?:\.[A-Z0-9]{1,4})?$/i', trim($code)) === 1;
    }
}
