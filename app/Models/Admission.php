<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Admission extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'guarantor_name',
        'guarantor_relationship',
        'guarantor_contact',
        'loa_reference',
    ];

    protected $casts = [
        'admitted_at' => 'datetime',
        'loa_reference' => 'encrypted',
        'loa_requested_amount' => 'decimal:2',
        'loa_approved_amount' => 'decimal:2',
        'loa_decided_at' => 'datetime',
    ];

    public const STATUS_REQUESTED = 'REQUESTED';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_ADMITTED = 'ADMITTED';

    public const STATUS_TRANSFERRED = 'TRANSFERRED';

    public const STATUS_DISCHARGED = 'DISCHARGED';

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function erVisit(): BelongsTo
    {
        return $this->belongsTo(ErVisit::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function attendingProvider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'attending_provider_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bedAssignments(): HasMany
    {
        return $this->hasMany(BedAssignment::class);
    }

    public function bedReservations(): HasMany
    {
        return $this->hasMany(BedReservation::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(PatientTransfer::class);
    }

    public function activeBedAssignment(): HasOne
    {
        return $this->hasOne(BedAssignment::class)
            ->where('status', 'ACTIVE')
            ->orderByDesc('assigned_at')
            ->orderByDesc('id');
    }

    public function discharge(): HasOne
    {
        return $this->hasOne(Discharge::class);
    }

    public function dischargeSummary(): HasOne
    {
        return $this->hasOne(DischargeSummary::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(EncounterBill::class);
    }

    public function billingClearanceRequests(): HasMany
    {
        return $this->hasMany(BillingClearanceRequest::class);
    }
}
