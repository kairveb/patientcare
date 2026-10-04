<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SymptomCheck extends Model
{
    protected $guarded = [];

    protected $casts = [
        'symptoms' => 'encrypted:array',
        'vitals' => 'encrypted:array',
        'ai_reasons' => 'encrypted:array',
        'red_flags' => 'encrypted:array',
        'requires_er' => 'boolean',
        'age' => 'integer',
        'urgency_score' => 'integer',
        'ai_confidence' => 'integer',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
