<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'needs' => 'array',
        'eta_at' => 'datetime',
        'status_updated_at' => 'datetime',
    ];

    public function histories(): HasMany
    {
        return $this->hasMany(TransportRequestHistory::class)->latest();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
