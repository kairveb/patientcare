<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationProviderVersion extends Model
{
    protected $guarded = [];

    protected $casts = ['source_version' => 'integer'];
}
