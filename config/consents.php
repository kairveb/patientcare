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
        'BDMS_SHARING' => [
            'title' => 'Billing partner data sharing',
            'version' => env('BDMS_SHARING_CONSENT_VERSION', '1.0'),
            'required' => false,
            'text' => 'I consent to sharing the minimum billing and payment information needed with the hospital billing partner to process statements and clearance.',
        ],
        'HICS_SHARING' => [
            'title' => 'Insurance partner data sharing',
            'version' => env('HICS_SHARING_CONSENT_VERSION', '1.0'),
            'required' => false,
            'text' => 'I consent to sharing the minimum insurance and admission or care-request information needed with the hospital insurance partner to process a letter of authorization.',
        ],
        'LIS_SHARING' => [
            'title' => 'Laboratory partner data sharing',
            'version' => env('LIS_SHARING_CONSENT_VERSION', '1.0'),
            'required' => false,
            'text' => 'I consent to sharing the minimum information required to process laboratory orders and return results.',
        ],
        'RIS_SHARING' => [
            'title' => 'Radiology partner data sharing',
            'version' => env('RIS_SHARING_CONSENT_VERSION', '1.0'),
            'required' => false,
            'text' => 'I consent to sharing the minimum information required to process imaging orders and return results.',
        ],
        'PMS_SHARING' => [
            'title' => 'Pharmacy partner data sharing',
            'version' => env('PMS_SHARING_CONSENT_VERSION', '1.0'),
            'required' => false,
            'text' => 'I consent to sharing the minimum prescription information required for pharmacy processing.',
        ],
        'EMRS_SHARING' => [
            'title' => 'Clinical record exchange data sharing',
            'version' => env('EMRS_SHARING_CONSENT_VERSION', '1.0'),
            'required' => false,
            'text' => 'I consent to the minimum necessary exchange of clinical record references and diagnosis information for care coordination.',
        ],
        'HRMS_SHARING' => [
            'title' => 'Provider availability coordination',
            'version' => env('HRMS_SHARING_CONSENT_VERSION', '1.0'),
            'required' => false,
            'text' => 'I consent to sharing minimum provider availability information for appointment scheduling and coverage coordination.',
        ],
        'HADS_SHARING' => [
            'title' => 'De-identified analytics export',
            'version' => env('HADS_SHARING_CONSENT_VERSION', '1.0'),
            'required' => false,
            'text' => 'I acknowledge that the hospital may share approved aggregate or de-identified operational analytics for quality and service planning.',
        ],
        'SAS_SHARING' => [
            'title' => 'Security and account administration',
            'version' => env('SAS_SHARING_CONSENT_VERSION', '1.0'),
            'required' => false,
            'text' => 'I acknowledge that minimum account status and approved role information may be exchanged for security administration.',
        ],
        'VRDS_SHARING' => [
            'title' => 'Patient transport dispatch',
            'version' => env('VRDS_SHARING_CONSENT_VERSION', '1.0'),
            'required' => false,
            'text' => 'Only generic transport requirements and hospital zone codes are sent to the dispatch partner. No patient identity or clinical details are shared.',
        ],
    ],
];
