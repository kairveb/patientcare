<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationPartner extends Model
{
    protected $guarded = [];

    protected $hidden = ['api_key_hash', 'webhook_signing_secret', 'outbound_token'];

    protected $casts = [
        'active' => 'boolean',
        'allowed_abilities' => 'array',
        'webhook_signing_secret' => 'encrypted',
        'outbound_token' => 'encrypted',
        'last_used_at' => 'datetime',
    ];

    public function outbox(): HasMany
    {
        return $this->hasMany(IntegrationOutbox::class);
    }
}
