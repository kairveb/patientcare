<?php

return [
    'otp_enabled' => (bool) env('OTP_ENABLED', true),
    'otp_ttl_minutes' => max(1, (int) env('OTP_TTL_MINUTES', 5)),
    'otp_max_attempts' => max(1, (int) env('OTP_MAX_ATTEMPTS', 5)),
    'otp_max_resends' => max(0, (int) env('OTP_MAX_RESENDS', 3)),
    'otp_max_sends' => max(1, (int) env('OTP_MAX_SENDS', 5)),
    'otp_resend_cooldown_seconds' => max(1, (int) env('OTP_RESEND_COOLDOWN_SECONDS', 30)),
    'login_max_attempts' => max(1, (int) env('LOGIN_MAX_ATTEMPTS', 5)),
    'login_lockout_minutes' => max(1, (int) env('LOGIN_LOCKOUT_MINUTES', 2)),
    'session_idle_minutes' => max(1, (int) env('SESSION_IDLE_MINUTES', 5)),
    'session_warning_seconds' => 30,
];
