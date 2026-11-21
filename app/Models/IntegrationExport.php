<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationExport extends Model
{
    protected $guarded = [];

    protected $casts = [
        'period_from' => 'date:Y-m-d',
        'period_to' => 'date:Y-m-d',
        'row_count' => 'integer',
        'expires_at' => 'datetime',
        'payload_snapshot' => 'array',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(IntegrationPartner::class, 'integration_partner_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
