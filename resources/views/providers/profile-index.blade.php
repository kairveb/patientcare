@extends('layouts.hims')
@section('title', 'Doctor Profiles')
@section('page-title', 'Doctor profiles')
@section('page-description', 'Maintain the patient-facing descriptions and consultation options.')
@section('content')
<div class="space-y-3">
    @foreach ($providers as $provider)
        <div class="flex flex-col justify-between gap-3 rounded-xl border bg-white p-4 sm:flex-row sm:items-center">
            <div>
                <p class="font-semibold">{{ $provider->full_name }}</p>
                <p class="text-sm text-slate-600">{{ $provider->specialties->pluck('name')->join(', ') }} · {{ $provider->department?->name ?? 'General' }}</p>
            </div>
            <a href="{{ route('providers.profile.edit', $provider) }}" class="rounded-lg border border-teal-700 px-4 py-2 text-center text-sm font-semibold text-teal-800">Edit profile</a>
        </div>
    @endforeach
    {{ $providers->links() }}
</div>
@endsection
