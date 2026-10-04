<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PatientIdentifierService
{
    private const VERIFICATION_COLUMNS = [
        'philhealth_number' => ['philhealth_verified_by', 'philhealth_verified_at'],
        'senior_id_number' => ['senior_id_verified_by', 'senior_id_verified_at'],
        'pwd_id_number' => ['pwd_id_verified_by', 'pwd_id_verified_at'],
        'hmo_member_id' => ['hmo_member_verified_by', 'hmo_member_verified_at'],
    ];

    public function __construct(protected AuditLogService $audit) {}

    public static function verificationColumns(): array
    {
        return self::VERIFICATION_COLUMNS;
    }

    public function verify(Patient $patient, string $identifier, User $verifier): Patient
    {
        $columns = self::VERIFICATION_COLUMNS[$identifier] ?? null;
        if ($columns === null) {
            throw ValidationException::withMessages([
                'identifier' => ['Select a supported patient identifier to verify.'],
            ]);
        }

        if (blank($patient->{$identifier})) {
            throw ValidationException::withMessages([
                'identifier' => ['The identifier must be entered before it can be verified.'],
            ]);
        }

        $verifiedAt = now();
        $patient->update([
            $columns[0] => $verifier->id,
            $columns[1] => $verifiedAt,
        ]);

        $this->audit->log(
            'VERIFY_PATIENT_IDENTIFIER',
            'patient',
            $patient->id,
            'SUCCESS',
            ['identifier_type' => $identifier, 'verified_at' => $verifiedAt->toIso8601String()],
            $verifier->id
        );

        return $patient->fresh();
    }
}
