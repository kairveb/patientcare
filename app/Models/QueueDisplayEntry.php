<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueDisplayEntry extends Model
{
    protected $guarded = [];

    public const SERVICE_OPD = 'OPD';
    public const SERVICE_PHARMACY = 'PHARMACY';

    public const STATUS_WAITING = 'WAITING';
    public const STATUS_CALLED = 'CALLED';
    public const STATUS_IN_SERVICE = 'IN_SERVICE';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public function getQueueNumberAttribute(): string
    {
        $prefix = $this->service === self::SERVICE_PHARMACY ? 'P' : 'O';

        return sprintf('%s-%06d', $prefix, $this->id);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }
}
