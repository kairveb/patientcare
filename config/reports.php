<?php

return [
    'password_confirm_minutes' => max(1, (int) env('REPORT_PASSWORD_CONFIRM_MINUTES', 5)),
];
