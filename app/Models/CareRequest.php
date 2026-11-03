<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CareRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'description' => 'encrypted',
        'remarks' => 'encrypted',
        'result_text' => 'encrypted',
        'decided_at' => 'datetime',
    ];

    public const TYPE_LAB = 'lab';

    public const TYPE_IMAGING = 'imaging';

    public const TYPE_PROCEDURE = 'procedure';

    public const URGENCY_ROUTINE = 'routine';

    public const URGENCY_URGENT = 'urgent';

    public const STATUS_REQUESTED = 'REQUESTED';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function clinicalDocument(): BelongsTo
    {
        return $this->belongsTo(ClinicalDocument::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(CareRequestStatusHistory::class)->orderBy('created_at');
    }
}
