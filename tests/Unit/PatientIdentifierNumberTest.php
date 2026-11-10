<?php

namespace Tests\Unit;

use App\Rules\PatientIdentifierNumber;
use App\Rules\PhilHealthNumber;
use App\Support\PatientIdentifierNormalizer;
use PHPUnit\Framework\TestCase;

class PatientIdentifierNumberTest extends TestCase
{
    public function test_patient_identifier_numbers_accept_digits_and_optional_separators_only(): void
    {
        $rule = new PatientIdentifierNumber;
        $errors = [];

        $rule->validate('senior_id_number', '1234 567-89', function (string $message) use (&$errors): void {
            $errors[] = $message;
        });
        $rule->validate('pwd_id_number', 'SC-123', function (string $message) use (&$errors): void {
            $errors[] = $message;
        });

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Please enter numbers only.', $errors[0]);
    }

    public function test_philhealth_accepts_twelve_digits_with_optional_separators(): void
    {
        $rule = new PhilHealthNumber;
        $errors = [];

        $rule->validate('philhealth_number', '1234-5678 9012', function (string $message) use (&$errors): void {
            $errors[] = $message;
        });

        $this->assertSame([], $errors);
        $this->assertSame('123456789012', PatientIdentifierNormalizer::normalize('1234-5678 9012'));
    }

    public function test_all_patient_identifier_fields_are_normalized_before_service_persistence(): void
    {
        $this->assertSame([
            'philhealth_number' => '123456789012',
            'senior_id_number' => '12345',
            'pwd_id_number' => '67890',
            'hmo_member_id' => '24680',
        ], PatientIdentifierNormalizer::normalizeFields([
            'philhealth_number' => '1234 5678-9012',
            'senior_id_number' => '12-345',
            'pwd_id_number' => '67 890',
            'hmo_member_id' => '24-680',
        ]));
    }
}
