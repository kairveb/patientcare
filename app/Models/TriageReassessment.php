<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TriageReassessment extends Model
{
    protected $guarded = [];

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_CONFIRMED = 'CONFIRMED';

    protected $casts = [
        'recorded_at' => 'datetime',
        'vitals' => 'encrypted:array',
        'ai_confidence' => 'integer',
        'ai_reasons' => 'encrypted:array',
        'more_severe' => 'boolean',
        'confirmed_at' => 'datetime',
    ];

    public function erVisit(): BelongsTo
    {
        return $this->belongsTo(ErVisit::class);
    }

    public function triageAssessment(): BelongsTo
    {
        return $this->belongsTo(TriageAssessment::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
