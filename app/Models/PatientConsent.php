<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientConsent extends Model
{
    protected $guarded = [];

    protected $casts = ['granted' => 'boolean', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \LogicException('Patient consent history is append-only.');
        });
        static::deleting(static function (): void {
            throw new \LogicException('Patient consent history is append-only.');
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
