<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PatientMergeService
{
    private const PATIENT_LINKED_TABLES = [
        'admissions',
        'appointments',
        'beds',
        'clinical_documents',
        'encounter_bills',
        'encounters',
        'emergency_contacts',
        'er_visits',
        'patient_addresses',
        'patient_identifiers',
        'pre_arrival_profiles',
        'prescriptions',
        'symptom_checks',
        'telehealth_ai_summaries',
        'telehealth_intakes',
        'telehealth_referrals',
        'telehealth_sessions',
        'triage_assessments',
        'triage_vitals',
        'vitals',
        'waitlists',
    ];

    private const PROFILE_FIELDS = [
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'date_of_birth',
        'sex',
        'civil_status',
        'nationality',
        'religion',
        'occupation',
        'phone',
        'email',
        'allergies',
        'insurance_number',
        'philhealth_number',
        'senior_id_number',
        'pwd_id_number',
        'hmo_name',
        'hmo_member_id',
        'guarantor_name',
        'guarantor_relationship',
        'guarantor_contact',
    ];

    public function __construct(
        protected PatientService $patients,
        protected AuditLogService $audit,
    ) {
    }

    public function merge(Patient $source, array $data, User $actor): Patient
    {
        if (! $actor->hasRole('registration', 'super-admin') || ! $actor->hasPermission('update-patients')) {
            abort(403, 'Only Registration Staff or Super Admin may merge patient records.');
        }

        return DB::transaction(function () use ($source, $data, $actor): Patient {
            $targetQuery = Patient::query();
            if (! empty($data['target_patient_id'])) {
                $targetQuery->whereKey($data['target_patient_id']);
            } else {
                $targetQuery->where('mrn', $data['target_mrn']);
            }
            $targetId = $targetQuery->value('id');

            if (! $targetId || (int) $source->id === (int) $targetId) {
                throw ValidationException::withMessages([
                    'target_patient_id' => ['Choose a different active patient record as the surviving record.'],
                ]);
            }

            $patients = Patient::withTrashed()
                ->whereIn('id', [(int) $source->id, (int) $targetId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $lockedSource = $patients->get($source->id);
            $target = $patients->get($targetId);

            if (! $lockedSource || ! $target || $lockedSource->trashed() || $target->trashed()
                || $lockedSource->merged_into_patient_id || $target->merged_into_patient_id) {
                throw ValidationException::withMessages([
                    'patient' => ['Only active, unmerged patient records can be merged.'],
                ]);
            }

            if ($target->is_temporary_identity) {
                throw ValidationException::withMessages([
                    'target_patient_id' => ['The surviving record must have a completed patient identity.'],
                ]);
            }

            if (! $lockedSource->is_temporary_identity) {
                $match = $this->patients->scoreDuplicatePatients($lockedSource, $target);
                if (! $match['has_anchor'] || $match['score'] < 45 || $match['name_score'] < 40) {
                    throw ValidationException::withMessages([
                        'target_patient_id' => ['These patient records do not meet the minimum duplicate-match threshold.'],
                    ]);
                }
            }

            if ($lockedSource->user_id && $target->user_id) {
                throw ValidationException::withMessages([
                    'target_patient_id' => ['Both records have separate patient portal accounts. Resolve account ownership before merging.'],
                ]);
            }
            $transferPatientAccount = ! $target->user_id && $lockedSource->user_id !== null;

            $profileUpdates = [];
            foreach (self::PROFILE_FIELDS as $field) {
                if (blank($target->getAttribute($field)) && filled($lockedSource->getAttribute($field))) {
                    $profileUpdates[$field] = $lockedSource->getAttribute($field);
                }
            }
            if ($lockedSource->verified && ! $target->verified) {
                $profileUpdates['verified'] = true;
            }
            foreach ([
                'philhealth_number' => ['philhealth_verified_by', 'philhealth_verified_at'],
                'senior_id_number' => ['senior_id_verified_by', 'senior_id_verified_at'],
                'pwd_id_number' => ['pwd_id_verified_by', 'pwd_id_verified_at'],
                'hmo_member_id' => ['hmo_member_verified_by', 'hmo_member_verified_at'],
            ] as $identifier => [$verifiedBy, $verifiedAt]) {
                $sourceIdentifier = $lockedSource->getAttribute($identifier);
                $targetIdentifier = $target->getAttribute($identifier);
                if (
                    filled($sourceIdentifier)
                    && (blank($targetIdentifier) || $sourceIdentifier === $targetIdentifier)
                    && filled($lockedSource->getAttribute($verifiedAt))
                    && blank($target->getAttribute($verifiedAt))
                ) {
                    $profileUpdates[$verifiedBy] = $lockedSource->getAttribute($verifiedBy);
                    $profileUpdates[$verifiedAt] = $lockedSource->getAttribute($verifiedAt);
                }
            }

            if ($profileUpdates !== []) {
                $target->forceFill($profileUpdates)->save();
            }

            $sourceUserId = $lockedSource->user_id;
            $movedRecordCounts = [];
            foreach (self::PATIENT_LINKED_TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'patient_id')) {
                    continue;
                }

                $movedRecordCounts[$table] = DB::table($table)
                    ->where('patient_id', $lockedSource->id)
                    ->update(['patient_id' => $target->id]);
            }

            $mergedAliasCount = Patient::withTrashed()
                ->where('merged_into_patient_id', $lockedSource->id)
                ->update(['merged_into_patient_id' => $target->id]);
            $mergedAt = now();
            $lockedSource->forceFill([
                'user_id' => null,
                'merged_into_patient_id' => $target->id,
                'merged_by' => $actor->id,
                'merged_at' => $mergedAt,
            ])->save();
            $lockedSource->delete();

            if ($transferPatientAccount) {
                $target->forceFill(['user_id' => $sourceUserId])->save();
            }

            $backfilledFields = array_keys($profileUpdates);
            if ($transferPatientAccount) {
                $backfilledFields[] = 'user_id';
            }
            $metadata = [
                'surviving_patient_id' => $target->id,
                'moved_record_counts' => $movedRecordCounts,
                'merged_alias_count' => $mergedAliasCount,
                'profile_fields_backfilled' => $backfilledFields,
                'patient_portal_account_transferred' => $sourceUserId !== null,
            ];
            $this->audit->log('MERGE_PATIENT_SOURCE', 'patient', $lockedSource->id, 'SUCCESS', $metadata, $actor->id);
            $this->audit->log('MERGE_PATIENT_TARGET', 'patient', $target->id, 'SUCCESS', [
                'merged_patient_id' => $lockedSource->id,
                'moved_record_counts' => $movedRecordCounts,
                'merged_alias_count' => $mergedAliasCount,
                'profile_fields_backfilled' => $backfilledFields,
            ], $actor->id);

            return $target->fresh();
        });
    }
}
