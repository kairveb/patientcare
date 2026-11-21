<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Encounter extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'follow_up_date' => 'date:Y-m-d',
    ];

    public const TYPE_OUTPATIENT = 'OUTPATIENT';

    public const TYPE_TELEHEALTH = 'TELEHEALTH';

    public const TYPE_EMERGENCY = 'EMERGENCY';

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_COMPLETED = 'COMPLETED';

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function admission(): HasOne
    {
        return $this->hasOne(Admission::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(EncounterNote::class);
    }

    public function vitals(): HasMany
    {
        return $this->hasMany(Vital::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function careRequests(): HasMany
    {
        return $this->hasMany(CareRequest::class);
    }

    public function dischargeSummary(): HasOne
    {
        return $this->hasOne(DischargeSummary::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(EncounterBill::class);
    }

    public function integrationClinicalDocuments(): HasMany
    {
        return $this->hasMany(IntegrationClinicalDocument::class);
    }
}
