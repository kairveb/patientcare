@extends('layouts.legal')

@section('title', 'Terms and Agreement')
@section('content')
<h1 class="text-3xl font-bold">Terms and Agreement</h1>
<p class="mt-2 text-sm text-slate-600">Version {{ $version }} · Effective {{ $effectiveDate }}</p>
<p class="mt-6">These terms apply to this Hospital Information System capstone. It is a demonstration and educational system, not a substitute for professional medical judgment or a production hospital platform.</p>

<h2>Account responsibilities</h2>
<p>Use only your own account, keep authentication codes private, provide accurate information, and promptly report suspected unauthorized access. Do not access records beyond your authorized role.</p>

<h2>Clinical services and AI</h2>
<p>Clinical decisions remain with qualified healthcare professionals. AI-assisted triage may organize reported symptoms but cannot diagnose, prescribe, or replace emergency care. Do not delay emergency treatment to use this system.</p>

<h2>Telehealth and availability</h2>
<p>Telehealth depends on connectivity and compatible devices and may be unavailable. Use a private environment. Demo video mode is not suitable for real patient consultations. Appointment requests may need staff approval; submitted requests are not confirmed until their status says confirmed.</p>

<h2>Privacy and records</h2>
<p>Use of personal and health information is described in the <a class="text-teal-800 underline" href="{{ route('privacy.notice') }}">Privacy Notice</a>. Clinical records may need to be retained under applicable requirements. Account use and access may be logged for security and accountability.</p>

<h2>Acceptable use and service limitations</h2>
<p>Do not disrupt the service, attempt to bypass access controls, upload unlawful or malicious content, or treat this educational application as an emergency contact channel. The capstone may be changed, unavailable, or contain defects; report safety concerns to the institution operating the demonstration.</p>

<h2>Questions</h2>
<p>Data Protection Officer: {{ $dpo['name'] ?: 'Not configured' }} · {{ $dpo['email'] ?: 'Not configured' }} · {{ $dpo['phone'] ?: 'Not configured' }}</p>
@endsection
