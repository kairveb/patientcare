<?php

return [
    'provider' => env('TELEHEALTH_PROVIDER'),
    'join_early_minutes' => max(0, (int) env('TELEHEALTH_JOIN_EARLY_MINUTES', 15)),
    'join_late_minutes' => max(0, (int) env('TELEHEALTH_JOIN_LATE_MINUTES', 30)),
    'join_token_ttl_minutes' => 5,
];
