<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('er-alerts', function ($user): bool {
    return $user->hasAnyRole(['nurse', 'doctor', 'super-admin']);
});
