<?php

return [
    'types' => [
        'GENERAL' => [
            'title' => 'General care consent',
            'version' => '1.0',
            'required' => true,
            'text' => 'I consent to the hospital providing and documenting the healthcare services I request. I understand I may ask questions about my care at any time.',
        ],
        'DATA_PRIVACY' => [
            'title' => 'Privacy Notice acknowledgment',
            'version' => env('PRIVACY_NOTICE_VERSION', '1.0'),
            'required' => true,
            'text' => 'I acknowledge the hospital privacy notice and consent to the collection, use, storage, and authorized processing of my personal and sensitive personal information for registration, healthcare delivery, and related lawful hospital operations under the Data Privacy Act of 2012 (RA 10173).',
        ],
        'TERMS_OF_USE' => [
            'title' => 'Terms and Agreement',
            'version' => env('TERMS_VERSION', '1.0'),
            'required' => true,
            'text' => 'I have read and agree to the terms for using this demonstration hospital information system.',
        ],
        'TELEHEALTH' => [
            'title' => 'Telehealth consent',
            'version' => '1.0',
            'required' => false,
            'text' => 'I consent to consultation through audio/video telehealth. I understand technical limitations may affect the consultation and that my doctor may recommend an in-person or emergency assessment.',
        ],
        'AI_ASSISTED' => [
            'title' => 'AI-assisted care consent',
            'version' => '1.0',
            'required' => false,
            'text' => 'I consent to the use of AI-assisted tools to organize my reported symptoms and support clinical triage or consultation. AI output is decision support only, is not a diagnosis, and must be reviewed by a licensed healthcare professional.',
        ],
    ],
];
