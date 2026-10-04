@extends('layouts.guest')

@section('title', 'Pre-register for Care')
@section('page-kicker', 'Patient access')
@section('page-title', 'Pre-register for Care')
@section('page-badge', 'No account required')

@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    <div class="panel-card p-6">
        <h2 class="text-2xl font-semibold text-slate-900">Pre-register before arrival</h2>
        <p class="mt-1 text-sm text-slate-600">Submit your patient and visit information before coming to the hospital. Staff will review it at arrival.</p>
    </div>

    <form method="POST" action="{{ route('public.pre-register.store') }}" class="panel-card space-y-6 p-6">
        @csrf
        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
        @endif

        @include('portal.partials.pre-registration-fields', [
            'patient' => null,
            'address' => null,
            'contact' => null,
        ])

        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
            <label class="flex items-start gap-3 text-sm text-amber-950">
                <input type="checkbox" name="data_privacy_consent" value="1" required @checked(old('data_privacy_consent')) class="mt-1 rounded border-amber-400">
                <span><strong>{{ $consentTypes['DATA_PRIVACY']['title'] }} (v{{ $consentTypes['DATA_PRIVACY']['version'] }})</strong><br>{{ $consentTypes['DATA_PRIVACY']['text'] }}</span>
            </label>
            @error('data_privacy_consent')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
        </div>

        <div class="flex justify-end"><button type="submit" class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white">Submit pre-registration</button></div>
    </form>
</div>
@endsection
