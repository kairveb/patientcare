<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelehealthIntake extends Model
{
    protected $guarded = [];

    protected $casts = [
        'symptoms' => 'encrypted',
        'allergies' => 'encrypted',
        'current_medications' => 'encrypted',
        'vitals' => 'encrypted:array',
        'ai_reasons' => 'encrypted:array',
        'severity' => 'integer',
        'is_emergency' => 'boolean',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TelehealthSession::class, 'telehealth_session_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
