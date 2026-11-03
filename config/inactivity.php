<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Inactivity Auto-Logout
    |--------------------------------------------------------------------------
    |
    | When enabled, authenticated users are automatically logged out after the
    | configured number of seconds of inactivity. This is evaluated on each
    | authenticated web request.
    |
    */

    'enabled' => env('INACTIVITY_ENABLED', true),

    'timeout' => max(1, (int) env('SESSION_IDLE_MINUTES', 5)) * 60,
];
