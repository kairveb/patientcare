<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentReassignmentHistory extends Model
{
    protected $guarded = [];

    protected $casts = ['starts_at' => 'datetime'];

    public function reassignment(): BelongsTo
    {
        return $this->belongsTo(AppointmentReassignment::class, 'appointment_reassignment_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
