<?php

namespace App\Services;

use App\Models\EmergencyContact;
use App\Models\Patient;
use App\Models\PatientAddress;
use App\Models\PatientIdentifier;
use App\Models\PreArrivalProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PatientService
{
    /**
     * Generate the next unique MRN.
     */
    public function generateMpn(): string
    {
        $year = now()->format('Y');
        $candidates = Patient::withTrashed()
            ->where('mrn', 'like', "MRN-{$year}-%")
            ->lockForUpdate()
            ->pluck('mrn');

        $maxSequence = 0;
        foreach ($candidates as $number) {
            if (preg_match('/^MRN-' . preg_quote($year, '/') . '-(\d{6})$/', $number, $matches)) {
                $maxSequence = max($maxSequence, (int) $matches[1]);
            }
        }

        return sprintf('MRN-%s-%06d', $year, $maxSequence + 1);
    }

    /**
     * Register a new patient with address, emergency contact, and identifiers.
     */
    public function register(array $data, ?int $userId = null): Patient
    {
        return DB::transaction(function () use ($data, $userId) {
            $data['mrn'] = $data['mrn'] ?? $this->generateMpn();
            $data['verified'] = $data['verified'] ?? false;

            // Strip relational keys that belong in separate tables
            $patientData = Arr::except($data, ['address', 'emergency_contact', 'identifiers']);
            $patientData['reference_code'] = $this->generateUniqueReferenceCode();
            $patient = Patient::create($patientData);

            if (!empty($data['address'])) {
                PatientAddress::create([
                    'patient_id' => $patient->id,
                    'line1' => $data['address']['line1'] ?? '',
                    'line2' => $data['address']['line2'] ?? null,
                    'region' => $data['address']['region'] ?? null,
                    'barangay' => $data['address']['barangay'] ?? null,
                    'city' => $data['address']['city'] ?? null,
                    'province' => $data['address']['province'] ?? null,
                    'submunicipality' => $data['address']['submunicipality'] ?? null,
                    'region_psgc_code' => $data['address']['region_psgc_code'] ?? null,
                    'province_psgc_code' => $data['address']['province_psgc_code'] ?? null,
                    'city_psgc_code' => $data['address']['city_psgc_code'] ?? null,
                    'submunicipality_psgc_code' => $data['address']['submunicipality_psgc_code'] ?? null,
                    'barangay_psgc_code' => $data['address']['barangay_psgc_code'] ?? null,
                    'postal_code' => $data['address']['postal_code'] ?? null,
                    'primary' => true,
                ]);
            }

            if (!empty($data['emergency_contact'])) {
                EmergencyContact::create([
                    'patient_id' => $patient->id,
                    'name' => $data['emergency_contact']['name'] ?? '',
                    'relationship' => $data['emergency_contact']['relationship'] ?? '',
                    'phone' => $data['emergency_contact']['phone'] ?? '',
                ]);
            }

            if (!empty($data['identifiers'])) {
                foreach ($data['identifiers'] as $identifier) {
                    if (!empty($identifier['type']) && !empty($identifier['value'])) {
                        PatientIdentifier::create([
                            'patient_id' => $patient->id,
                            'type' => $identifier['type'],
                            'value' => $identifier['value'],
                        ]);
                    }
                }
            }

            app(AuditLogService::class)->createPatient($patient->id, ['mrn' => $patient->mrn]);

            return $patient;
        });
    }

    public function generateUniqueReferenceCode(): string
    {
        do {
            $digits = '';
            for ($index = 0; $index < 16; $index++) {
                $digits .= (string) random_int(0, 9);
            }
            $code = 'PRC-'.$digits;
        } while (Patient::withTrashed()->where('reference_code', $code)->exists());

        return $code;
    }

    /**
     * @return array{status: 'found'|'merged'|'not_found', patient: ?Patient}
     */
    public function lookupByReferenceCode(User $actor, ?string $input, ?int $expectedPatientId = null): array
    {
        return $this->resolvePatientReferenceCode($actor, $input, $expectedPatientId);
    }

    /**
     * @return array{status: 'found'|'merged'|'expired'|'not_eligible'|'not_found', patient: ?Patient, profile: ?PreArrivalProfile, code_type: ?string}
     */
    public function resolvePatientReferenceCode(User $actor, ?string $input, ?int $expectedPatientId = null): array
    {
        abort_if($actor->isPatient(), 403, 'Patients cannot look up other patient records.');

        [$codeType, $code] = $this->normalizePatientReferenceCode($input);
        $status = 'not_found';
        $patient = null;
        $profile = null;

        if ($codeType === 'PRC') {
            $candidate = Patient::withTrashed()->where('reference_code', $code)->first();

            if ($candidate && $candidate->trashed() && $candidate->merged_into_patient_id) {
                if ($actor->hasRole('registration', 'super-admin')) {
                    $survivor = $this->activeMergeSurvivor($candidate);
                    if ($survivor && ($expectedPatientId === null || (int) $survivor->id === $expectedPatientId)) {
                        $status = 'merged';
                        $patient = $survivor;
                    }
                }
            } elseif ($candidate && ! $candidate->trashed()
                && $this->queryVisibleTo($actor)->whereKey($candidate->id)->exists()
                && ($expectedPatientId === null || (int) $candidate->id === $expectedPatientId)) {
                $status = 'found';
                $patient = $candidate;
            }
        } elseif ($codeType === 'PAC') {
            $profile = PreArrivalProfile::query()
                ->with('patient')
                ->whereRaw('UPPER(reference_code) = ?', [$code])
                ->first();
            $candidate = $profile?->patient;

            if ($profile && $profile->status === 'expired') {
                $status = 'expired';
            } elseif ($profile && ! $profile->isEligibleForCheckIn()) {
                $status = 'not_eligible';
            } elseif ($profile && $candidate
                && ($expectedPatientId === null || (int) $candidate->id === $expectedPatientId)) {
                $status = 'found';
                $patient = $candidate;
            } elseif ($profile && $candidate) {
                $status = 'not_eligible';
            }
        }

        app(AuditLogService::class)->log(
            'LOOKUP_PATIENT_REFERENCE_CODE',
            'patient_reference_code_lookup',
            null,
            in_array($status, ['found', 'merged'], true) ? 'SUCCESS' : 'FAILURE',
            ['result' => $status, 'code_type' => $codeType],
            $actor->id
        );

        return [
            'status' => $status,
            'patient' => $patient,
            'profile' => $profile,
            'code_type' => $codeType,
        ];
    }

    public function isPatientReferenceCodeInput(?string $input): bool
    {
        return preg_match('/^\s*(PRC|PAC)/i', (string) $input) === 1;
    }

    public function ensureReferenceCode(Patient $patient): Patient
    {
        if (blank($patient->reference_code)) {
            $patient->forceFill([
                'reference_code' => $this->generateUniqueReferenceCode(),
            ])->saveQuietly();
        }

        return $patient;
    }

    public function backfillReferenceCodes(): int
    {
        $updated = 0;

        Patient::withTrashed()
            ->whereNull('reference_code')
            ->chunkById(200, function ($patients) use (&$updated): void {
                foreach ($patients as $patient) {
                    $patient->forceFill([
                        'reference_code' => $this->generateUniqueReferenceCode(),
                    ])->saveQuietly();
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function normalizePatientReferenceCode(?string $input): array
    {
        $value = strtoupper(trim((string) $input));

        if (preg_match('/^PRC-?([0-9]{16})$/', $value, $matches)) {
            return ['PRC', 'PRC-'.$matches[1]];
        }

        if (preg_match('/^PAC-?([0-9]{4})$/', $value, $matches)) {
            return ['PAC', 'PAC-'.$matches[1]];
        }

        return [null, null];
    }

    private function activeMergeSurvivor(Patient $patient): ?Patient
    {
        $visited = [];
        $survivorId = $patient->merged_into_patient_id;

        while ($survivorId && ! in_array($survivorId, $visited, true)) {
            $visited[] = $survivorId;
            $survivor = Patient::withTrashed()->find($survivorId);
            if (! $survivor) {
                return null;
            }
            if (! $survivor->trashed() && ! $survivor->merged_into_patient_id) {
                return $survivor;
            }

            $survivorId = $survivor->merged_into_patient_id;
        }

        return null;
    }

    /**
     * Find likely duplicate patients using normalized name, date of birth, and contact matches.
     */
    public function findDuplicates(array $data, ?int $excludePatientId = null): array
    {
        $dob = $this->dateValue($data['date_of_birth'] ?? null);
        $phone = $this->normalizePhone($data['phone'] ?? null);
        $email = Str::lower(trim((string) ($data['email'] ?? '')));

        if (! $dob && strlen($phone) < 7 && $email === '') {
            return [];
        }

        $candidates = Patient::query()
            ->whereNull('merged_into_patient_id')
            ->where(function (Builder $query) use ($dob, $phone, $email): void {
                if ($dob) {
                    $query->orWhereDate('date_of_birth', $dob);
                }

                if (strlen($phone) >= 7) {
                    $query->orWhere('phone', 'like', '%'.substr($phone, -7));
                }

                if ($email !== '') {
                    $query->orWhereRaw('LOWER(email) = ?', [$email]);
                }
            })
            ->when($excludePatientId, fn (Builder $query) => $query->where('id', '!=', $excludePatientId))
            ->get();

        return $candidates
            ->map(function (Patient $candidate) use ($data): ?array {
                $match = $this->scorePatientData($data, $candidate);
                if (! $match['has_anchor'] || $match['score'] < 45 || $match['name_score'] < 40) {
                    return null;
                }

                return [
                    'id' => $candidate->id,
                    'mrn' => $candidate->mrn,
                    'first_name' => $candidate->first_name,
                    'last_name' => $candidate->last_name,
                    'date_of_birth' => $candidate->date_of_birth?->toDateString(),
                    'similarity_score' => $match['score'],
                    'match_reasons' => $match['reasons'],
                ];
            })
            ->filter()
            ->sortByDesc('similarity_score')
            ->take(10)
            ->values()
            ->all();
    }

    public function scoreDuplicatePatients(Patient $first, Patient $second): array
    {
        return $this->scorePatientData([
            'first_name' => $first->first_name,
            'last_name' => $first->last_name,
            'date_of_birth' => $first->date_of_birth?->toDateString(),
            'phone' => $first->phone,
            'email' => $first->email,
        ], $second);
    }

    private function scorePatientData(array $data, Patient $candidate): array
    {
        $firstNameScore = $this->similarity($data['first_name'] ?? '', $candidate->first_name);
        $lastNameScore = $this->similarity($data['last_name'] ?? '', $candidate->last_name);
        $nameScore = (int) round(($firstNameScore + $lastNameScore) / 2);
        $dob = $this->dateValue($data['date_of_birth'] ?? null);
        $dobMatches = $dob !== null && $dob === $candidate->date_of_birth?->toDateString();
        $phone = $this->normalizePhone($data['phone'] ?? null);
        $phoneMatches = $phone !== '' && $phone === $this->normalizePhone($candidate->phone);
        $email = Str::lower(trim((string) ($data['email'] ?? '')));
        $emailMatches = $email !== '' && $email === Str::lower(trim((string) $candidate->email));
        $score = (int) round(($nameScore * 0.5) + ($dobMatches ? 30 : 0) + ($phoneMatches ? 15 : 0) + ($emailMatches ? 5 : 0));
        $reasons = [];

        if ($nameScore > 0) {
            $reasons[] = 'Name similarity '.$nameScore.'%';
        }
        if ($dobMatches) {
            $reasons[] = 'Date of birth matches';
        }
        if ($phoneMatches) {
            $reasons[] = 'Contact number matches';
        }
        if ($emailMatches) {
            $reasons[] = 'Email matches';
        }

        return [
            'score' => $score,
            'name_score' => $nameScore,
            'has_anchor' => $dobMatches || $phoneMatches || $emailMatches,
            'reasons' => $reasons,
        ];
    }

    private function similarity(?string $first, ?string $second): int
    {
        $first = $this->normalizeName($first);
        $second = $this->normalizeName($second);

        if ($first === '' || $second === '') {
            return 0;
        }

        similar_text($first, $second, $percentage);

        return (int) round($percentage);
    }

    private function normalizeName(?string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii(trim((string) $name)))) ?? '';
    }

    private function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    private function dateValue(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : substr((string) $value, 0, 10);
    }

    /**
     * Search patients by any supported criteria.
     */
    public function search(User $user, ?string $term = null, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = $this->queryVisibleTo($user)
            ->with(['addresses', 'emergencyContacts'])
            ->orderBy('created_at', 'desc');

        if ($term) {
            $like = '%' . $term . '%';
            $query->where(function ($q) use ($term, $like) {
                $q->where('mrn', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('middle_name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        if (!empty($filters['date_of_birth'])) {
            $query->where('date_of_birth', $filters['date_of_birth']);
        }

        if (!empty($filters['sex'])) {
            $query->where('sex', $filters['sex']);
        }

        return $query->paginate(15);
    }

    public function queryVisibleTo(User $user): Builder
    {
        $query = Patient::query();

        if ($user->hasRole('doctor')) {
            $providerId = $user->provider?->id;
            if ($providerId) {
                $query->where(function (Builder $patientQuery) use ($providerId) {
                    $patientQuery
                        ->whereHas('encounters', fn (Builder $encounterQuery) => $encounterQuery->where('provider_id', $providerId))
                        ->orWhereHas('appointments', fn (Builder $appointmentQuery) => $appointmentQuery->where('provider_id', $providerId));
                });
            } else {
                $query->whereRaw('0 = 1');
            }
        } elseif ($user->hasRole('nurse')) {
            $query->where(function (Builder $patientQuery) {
                $patientQuery->whereHas('erVisits')
                    ->orWhereHas('triageAssessments');
            });
        }

        return $query;
    }

    public function findVisibleToOrFail(User $user, int $patientId): Patient
    {
        return $this->queryVisibleTo($user)
            ->with(['addresses', 'emergencyContacts', 'identifiers'])
            ->findOrFail($patientId);
    }

    /**
     * Update permitted patient demographics plus related records.
     */
    public function update(Patient $patient, array $data): Patient
    {
        return DB::transaction(function () use ($patient, $data) {
            foreach (PatientIdentifierService::verificationColumns() as $identifier => [$verifiedBy, $verifiedAt]) {
                if (array_key_exists($identifier, $data) && $data[$identifier] !== $patient->{$identifier}) {
                    $data[$verifiedBy] = null;
                    $data[$verifiedAt] = null;
                }
            }

            $patient->update(Arr::except($data, ['address', 'emergency_contact', 'identifiers']));

            if (!empty($data['address'])) {
                $this->upsertPrimaryAddress($patient, $data['address']);
            }

            if (!empty($data['emergency_contact'])) {
                $this->upsertEmergencyContact($patient, $data['emergency_contact']);
            }

            app(AuditLogService::class)->updatePatient($patient->id);

            return $patient;
        });
    }

    public function completeTemporaryIdentity(Patient $patient, array $data, ?int $userId = null): Patient
    {
        return DB::transaction(function () use ($patient, $data, $userId): Patient {
            $lockedPatient = Patient::query()->lockForUpdate()->findOrFail($patient->id);
            if (! $lockedPatient->is_temporary_identity) {
                throw ValidationException::withMessages([
                    'patient' => ['This patient record is not a temporary ER identity.'],
                ]);
            }

            $lockedPatient->update([
                ...$data,
                'is_temporary_identity' => false,
                'temporary_identity_label' => null,
            ]);

            app(AuditLogService::class)->log(
                'COMPLETE_TEMPORARY_PATIENT_IDENTITY',
                'patient',
                $lockedPatient->id,
                'SUCCESS',
                ['completed_fields' => array_keys($data)],
                $userId
            );

            return $lockedPatient->fresh();
        });
    }

    protected function upsertPrimaryAddress(Patient $patient, array $addressData): void
    {
        $address = $patient->addresses()->where('primary', true)->first();

        if ($address) {
            $address->fill($addressData)->save();
            return;
        }

        // line1 is NOT NULL for newly created address rows.
        $addressData['line1'] = $addressData['line1'] ?? '';
        PatientAddress::create(array_merge(['patient_id' => $patient->id, 'primary' => true], $addressData));
    }

    protected function upsertEmergencyContact(Patient $patient, array $contactData): void
    {
        $contact = $patient->emergencyContacts()->first();

        if ($contact) {
            $contact->fill($contactData)->save();
            return;
        }

        EmergencyContact::create(array_merge(['patient_id' => $patient->id], $contactData));
    }

    public function verify(Patient $patient, ?int $userId = null): Patient
    {
        $patient->update(['verified' => true]);
        app(AuditLogService::class)->log('VERIFY_PATIENT', 'patient', $patient->id, 'SUCCESS', null, $userId);
        return $patient;
    }
}
