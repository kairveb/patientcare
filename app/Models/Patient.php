<?php

namespace App\Models;

use App\Support\PatientIdentifierNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Patient extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $hidden = [
        'philhealth_number',
        'senior_id_number',
        'pwd_id_number',
        'hmo_name',
        'hmo_member_id',
        'guarantor_name',
        'guarantor_relationship',
        'guarantor_contact',
        'philhealth_verified_by',
        'philhealth_verified_at',
        'senior_id_verified_by',
        'senior_id_verified_at',
        'pwd_id_verified_by',
        'pwd_id_verified_at',
        'hmo_member_verified_by',
        'hmo_member_verified_at',
        'merged_into_patient_id',
        'merged_by',
        'merged_at',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'verified' => 'boolean',
        'merged_at' => 'datetime',
        'allergies' => 'encrypted',
        'insurance_number' => 'encrypted',
        'philhealth_number' => 'encrypted',
        'senior_id_number' => 'encrypted',
        'pwd_id_number' => 'encrypted',
        'hmo_member_id' => 'encrypted',
        'is_temporary_identity' => 'boolean',
        'philhealth_verified_at' => 'datetime',
        'senior_id_verified_at' => 'datetime',
        'pwd_id_verified_at' => 'datetime',
        'hmo_member_verified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Patient $patient): void {
            foreach (['philhealth_number', 'senior_id_number', 'pwd_id_number', 'hmo_member_id'] as $field) {
                if (! $patient->isDirty($field) || $patient->{$field} === null) {
                    continue;
                }

                $patient->setAttribute(
                    $field,
                    PatientIdentifierNormalizer::normalize($patient->{$field})
                );
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_patient_id');
    }

    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function erVisits(): HasMany
    {
        return $this->hasMany(ErVisit::class);
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class);
    }

    public function careRequests(): HasMany
    {
        return $this->hasMany(CareRequest::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(PatientAddress::class);
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class);
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(PatientIdentifier::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(PatientConsent::class);
    }

    public function dataSubjectRequests(): HasMany
    {
        return $this->hasMany(DataSubjectRequest::class);
    }

    public function vitals(): HasMany
    {
        return $this->hasMany(Vital::class);
    }

    public function triageAssessments(): HasMany
    {
        return $this->hasMany(TriageAssessment::class);
    }

    public function clinicalDocuments(): HasMany
    {
        return $this->hasMany(ClinicalDocument::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(EncounterBill::class);
    }

    public function preArrivalProfiles(): HasMany
    {
        return $this->hasMany(PreArrivalProfile::class);
    }

    public function symptomChecks(): HasMany
    {
        return $this->hasMany(SymptomCheck::class);
    }

    public function telehealthIntakes(): HasMany
    {
        return $this->hasMany(TelehealthIntake::class);
    }

    public function telehealthAiSummaries(): HasMany
    {
        return $this->hasMany(TelehealthAiSummary::class);
    }

    public function telehealthReferrals(): HasMany
    {
        return $this->hasMany(TelehealthReferral::class);
    }

    public function waitlists(): HasMany
    {
        return $this->hasMany(Waitlist::class);
    }

    public function primaryAddress()
    {
        return $this->addresses()->where('primary', true)->first();
    }

    public function primaryEmergencyContact()
    {
        return $this->emergencyContacts()->first();
    }

    public function getFullNameAttribute(): string
    {
        if ($this->is_temporary_identity) {
            return $this->temporary_identity_label
                ? 'Unknown patient ('.$this->temporary_identity_label.')'
                : 'Unknown patient';
        }

        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])));
    }

    public function ensureLookupCode(): string
    {
        if (! $this->lookup_code) {
            $this->lookup_code = strtoupper(Str::random(8));
        }

        return $this->lookup_code;
    }

    public function markPendingArrival(): void
    {
        $this->forceFill([
            'lookup_code' => $this->ensureLookupCode(),
            'pre_registration_status' => 'pending_arrival',
        ])->save();
    }

    public function getAgeAttribute(): ?int
    {
        return $this->date_of_birth?->age;
    }
}
