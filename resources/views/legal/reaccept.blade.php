@extends('layouts.legal')

@section('title', 'Review updated privacy documents')
@section('content')
<h1 class="text-3xl font-bold">Please review the updated documents</h1>
<p class="mt-2 text-sm text-slate-600">Privacy Notice version {{ $version }} · Effective {{ $effectiveDate }}</p>
@if (session('warning'))
    <p role="alert" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ session('warning') }}</p>
@endif
<div class="mt-6 space-y-3">
    <a class="block rounded-lg border bg-white p-4 font-semibold text-teal-800 underline" href="{{ route('privacy.notice') }}">Read the Privacy Notice</a>
    <a class="block rounded-lg border bg-white p-4 font-semibold text-teal-800 underline" href="{{ route('privacy.terms') }}">Read the Terms and Agreement</a>
</div>
<form method="POST" action="{{ route('privacy.acceptance.store') }}" class="mt-6 space-y-4 rounded-xl border bg-white p-5">
    @csrf
    <label class="flex items-start gap-3"><input type="checkbox" name="privacy_notice_accepted" value="1" required class="mt-1"><span>I have read and acknowledge the current Privacy Notice. (Version {{ $version }})</span></label>
    @error('privacy_notice_accepted')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
    <label class="flex items-start gap-3"><input type="checkbox" name="terms_accepted" value="1" required class="mt-1"><span>I have read and agree to the current Terms and Agreement (version {{ $termsVersion }}).</span></label>
    @error('terms_accepted')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
    <button class="rounded-lg bg-teal-700 px-4 py-2 font-semibold text-white">Accept and continue</button>
</form>
<form method="POST" action="{{ route('logout') }}" class="mt-4">@csrf<button class="text-sm text-slate-700 underline">Log out</button></form>
@endsection
