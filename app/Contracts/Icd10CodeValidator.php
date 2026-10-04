<?php

namespace App\Contracts;

interface Icd10CodeValidator
{
    public function isValid(string $code): bool;
}
