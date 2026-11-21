<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationOutbox extends Model
{
    protected $table = 'integration_outbox';

    protected $guarded = [];

    protected $casts = [
        'payload_reference' => 'array',
        'next_retry_at' => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(IntegrationPartner::class, 'integration_partner_id');
    }
}
