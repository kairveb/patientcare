<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSubjectRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'note' => 'encrypted',
        'response_note' => 'encrypted',
    ];

    public const TYPE_ACCESS_COPY = 'access_copy';
    public const TYPE_CORRECTION = 'correction';
    public const TYPE_RESTRICTION_OBJECTION = 'restriction_objection';
    public const TYPE_ERASURE = 'erasure';

    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_IN_REVIEW = 'IN_REVIEW';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_REJECTED = 'REJECTED';

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_ACCESS_COPY => 'Access or copy',
            self::TYPE_CORRECTION => 'Correction',
            self::TYPE_RESTRICTION_OBJECTION => 'Restriction or objection',
            self::TYPE_ERASURE => 'Erasure',
            default => 'Privacy',
        };
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(DataSubjectRequestStatusHistory::class)->orderBy('created_at');
    }
}
