@extends('layouts.hims')

@section('title', 'Emergency Instructions')
@section('page-title', 'Seek Emergency Care')
@section('page-kicker', 'Patient portal')

@section('content')
<div role="alert" class="mx-auto max-w-2xl rounded-xl border border-red-300 bg-red-50 p-6 text-red-950">
    <h1 class="text-xl font-bold">Do not wait for a virtual appointment</h1>
    <p class="mt-3 text-sm">Your telehealth intake has an AI urgency result indicating emergency assessment. This is decision support only and is not a diagnosis.</p>
    <p class="mt-3 text-sm font-semibold">Seek emergency care now. Call 911 if you may be in immediate danger.</p>
    <p class="mt-3 text-sm">Your doctor will see an emergency alert when reviewing this telehealth session.</p>
    <a href="{{ route('patients.portal.telehealth') }}" class="mt-5 inline-flex rounded-lg bg-red-800 px-4 py-2 text-sm font-semibold text-white">Return to telehealth sessions</a>
</div>
@endsection
