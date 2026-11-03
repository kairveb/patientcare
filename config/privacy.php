<?php

return [
    'notice_version' => env('PRIVACY_NOTICE_VERSION', '1.0'),
    'terms_version' => env('TERMS_VERSION', '1.0'),
    'effective_date' => env('PRIVACY_EFFECTIVE_DATE', '2026-10-13'),
    'ai_send_age_sex' => (bool) env('AI_SEND_AGE_SEX', true),
    'dpo' => [
        'name' => env('DPO_NAME'),
        'email' => env('DPO_EMAIL'),
        'phone' => env('DPO_PHONE'),
    ],
];
