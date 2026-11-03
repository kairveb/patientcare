@extends('layouts.legal')

@section('title', 'Privacy Notice')
@section('content')
<h1 class="text-3xl font-bold">Privacy Notice</h1>
<p class="mt-2 text-sm text-slate-600">Version {{ $version }} · Effective {{ $effectiveDate }}</p>
<p class="mt-6">This notice explains how this Hospital Information System capstone processes personal information. The system is designed to be aligned with RA 10173 and HIPAA principles; this notice is informational and is not a legal determination.</p>

<h2>Information collected</h2>
<p>Depending on the service used, records may include account and contact details, patient demographics, patient reference numbers, addresses, emergency contacts, health history, symptoms, vital signs, consultations, appointments, prescriptions, approval requests, telehealth consent, and security or audit events.</p>

<h2>Why information is used</h2>
<p>Information supports patient registration and identity matching, scheduling and care delivery, clinical documentation, safety triage, telehealth, approvals, patient communication, billing workflows, system security, quality review, and legal record-retention duties. The system should not use health data for unrelated advertising.</p>

<h2>Legal basis and consent</h2>
<p>Processing is intended to rely on the patient's informed choices where consent is appropriate, healthcare delivery, applicable legal obligations, and other lawful bases that must be determined for the actual hospital deployment. Required notice and terms acceptance are recorded with their document versions. Optional AI-assisted triage and telehealth consent can be withdrawn in the patient portal; withdrawal stops the related feature where the existing consent checks apply. Withdrawal does not erase care records already required for clinical or legal purposes.</p>

<h2>Who may access information</h2>
<p>Access is role-limited: patients see their own portal data; doctors access records needed for their care; nurse and registration roles are displayed as Staff and use their assigned operational access; administrators manage designated system operations; and super-admins have broader system administration access. Permissions, policies, and audit events are used, but authorized access still depends on correct account assignment and operational practice.</p>

<h2>AI-assisted triage</h2>
<p>With current AI-assisted consent, the symptom checker may send a minimized symptom description and, when configured, an age range and sex to the selected external AI provider (Gemini or OpenAI). Direct identifiers and obvious identifier patterns are masked before the request. The application stores a triage record for care continuity. External providers process requests under their own terms and retention settings; the hospital system cannot promise how an external provider retains request data. AI is decision support, not a diagnosis, and clinicians must review its output. Patients can use other care routes without AI triage.</p>

<h2>Telehealth confidentiality</h2>
<p>Telehealth is intended for private consultations. Use a private location and do not record or share a consultation without the required consent. Demo video mode is not suitable for real patient consultations. The system does not intentionally start consultation recording. Video service providers may have their own technical processing terms.</p>

<h2>Retention and security</h2>
<p>Records are retained according to applicable clinical, legal, and operational retention requirements. A request for erasure does not automatically delete clinical records; a request may instead result in restriction or anonymization where allowed. Security measures include role-based access, OTP login, idle session timeout, lockout controls, audit trails, and encryption of selected sensitive fields. These measures do not eliminate risk; whole-database encryption and backup encryption are not established by this notice.</p>

<h2>Your rights and requests</h2>
<p>You may request access or a copy, correction, restriction or objection, or erasure subject to applicable law, patient safety, and record-retention duties. The patient portal provides a request and review flow. You may also contact the Data Protection Officer or raise a complaint with the National Privacy Commission of the Philippines. The appropriate legal basis and response depend on the request and applicable law.</p>

<h2>Data Protection Officer</h2>
<p>Name: {{ $dpo['name'] ?: 'Not configured' }}<br>Email: {{ $dpo['email'] ?: 'Not configured' }}<br>Phone: {{ $dpo['phone'] ?: 'Not configured' }}</p>
<p class="mt-8 text-sm text-slate-500">This capstone notice is informational and should be reviewed by the deploying institution's privacy/legal lead before real-world use.</p>
@endsection
