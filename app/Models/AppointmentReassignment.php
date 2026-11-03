<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppointmentReassignment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'decided_at' => 'datetime',
        'suggested_starts_at' => 'datetime',
        'next_available_dates' => 'array',
    ];

    public const STATUS_PENDING_REVIEW = 'PENDING_REVIEW';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_DECLINED_BY_PATIENT = 'DECLINED_BY_PATIENT';
    public const STATUS_RESCHEDULED = 'RESCHEDULED';
    public const STATUS_WAITLISTED = 'WAITLISTED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function originalProvider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'original_provider_id');
    }

    public function suggestedProvider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'suggested_provider_id');
    }

    public function suggestedSlot(): BelongsTo
    {
        return $this->belongsTo(AppointmentSlot::class, 'suggested_slot_id');
    }

    public function unavailability(): BelongsTo
    {
        return $this->belongsTo(ProviderUnavailability::class, 'provider_unavailability_id');
    }

    public function waitlist(): BelongsTo
    {
        return $this->belongsTo(Waitlist::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(AppointmentReassignmentHistory::class);
    }
}
