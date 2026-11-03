<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderUnavailability extends Model
{
    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public const REASON_EMERGENCY = 'emergency';
    public const REASON_SICK_LEAVE = 'sick_leave';
    public const REASON_OTHER = 'other';

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reassignments(): HasMany
    {
        return $this->hasMany(AppointmentReassignment::class);
    }
}
