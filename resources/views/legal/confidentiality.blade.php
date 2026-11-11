@extends('layouts.legal')

@section('title', 'Staff and Clinician Confidentiality Notice')
@section('content')
<h1 class="text-3xl font-bold">Staff and Clinician Confidentiality Notice</h1>
<p class="mt-2 text-sm text-slate-600">Version {{ config('privacy.confidentiality_notice_version', '1.0') }}</p>
<div class="mt-6 space-y-4">
    <p>Access patient information only when needed for your assigned care, operational, or system-administration duties. Use approved HIMS workflows and protect credentials, screens, printouts, exports, and devices from unauthorized access.</p>
    <p>Do not disclose, copy, or discuss identifiable patient information outside authorized workflows. Use only the minimum information needed for your task and promptly report suspected loss, exposure, or unauthorized access through the institution's incident-reporting process.</p>
    <p>This notice supports confidentiality awareness. References in the HIMS privacy notice to the Data Privacy Act of 2012 (RA 10173) and HIPAA principles are informational, are not a legal determination, and do not certify that the application or organization is compliant. Follow institutional policy and applicable law.</p>
</div>
<form method="POST" action="{{ route('confidentiality.acknowledge') }}" class="mt-8 space-y-4">
    @csrf
    <label class="flex items-start gap-3 text-sm text-slate-700">
        <input type="checkbox" name="acknowledged" value="1" required class="mt-1 rounded border-slate-300">
        <span>I have read and understand this confidentiality notice.</span>
    </label>
    @error('acknowledged')<p class="text-sm text-rose-700">{{ $message }}</p>@enderror
    <button type="submit" class="rounded-lg bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white">Acknowledge and continue</button>
</form>
@endsection
