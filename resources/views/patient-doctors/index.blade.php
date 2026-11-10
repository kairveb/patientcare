@extends('layouts.hims')

@section('title', 'Find a Doctor')
@section('page-title', 'Find a doctor')
@section('page-description', 'Explore doctors, compare availability, and choose a time that works for you.')

@section('content')
<div class="space-y-6">
    <form method="GET" class="hims-filter-bar hims-filter-bar--grid gap-3 rounded-2xl border bg-white p-4 shadow-sm">
        <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Doctor name" class="rounded-lg border-slate-300 text-sm" aria-label="Search doctor name">
        <select name="department_id" class="rounded-lg border-slate-300 text-sm" aria-label="Department">
            <option value="">All departments</option>
            @foreach ($departments as $department)
                <option value="{{ $department->id }}" @selected(($filters['department_id'] ?? null) == $department->id)>{{ $department->name }}</option>
            @endforeach
        </select>
        <select name="specialty_id" class="rounded-lg border-slate-300 text-sm" aria-label="Specialty">
            <option value="">All specialties</option>
            @foreach ($specialties as $specialty)
                <option value="{{ $specialty->id }}" @selected(($filters['specialty_id'] ?? null) == $specialty->id)>{{ $specialty->name }}</option>
            @endforeach
        </select>
        <select name="consultation_type" class="rounded-lg border-slate-300 text-sm" aria-label="Consultation type">
            <option value="">Any consultation</option>
            <option value="in_person" @selected(($filters['consultation_type'] ?? null) === 'in_person')>In-person</option>
            <option value="virtual" @selected(($filters['consultation_type'] ?? null) === 'virtual')>Virtual</option>
        </select>
        <select name="available_within" class="rounded-lg border-slate-300 text-sm" aria-label="Availability window">
            <option value="">Any availability</option>
            @foreach ([7, 14, 30] as $days)
                <option value="{{ $days }}" @selected(($filters['available_within'] ?? null) == $days)>Available within {{ $days }} days</option>
            @endforeach
        </select>
        <button class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white sm:col-span-2 lg:col-span-5">Search doctors</button>
    </form>
    @if ($providers->isEmpty())
        <div class="rounded-xl border border-dashed bg-white p-8 text-center text-sm text-slate-600">No doctors match those filters. Try a wider search.</div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($providers as $provider)
                <article class="flex flex-col rounded-2xl border bg-white p-5 shadow-sm">
                    <div class="flex items-start gap-4">
                        @if ($provider->photo_path)
                            <img class="h-16 w-16 rounded-full object-cover" src="{{ Storage::disk('public')->url($provider->photo_path) }}" alt="">
                        @else
                            <div class="grid h-16 w-16 shrink-0 place-items-center rounded-full bg-teal-50 text-xl font-bold text-teal-800" aria-hidden="true">{{ mb_substr($provider->full_name, 0, 1) }}</div>
                        @endif
                        <div class="min-w-0">
                            <h2 class="font-semibold text-slate-900">{{ $provider->full_name }}</h2>
                            <p class="mt-1 text-sm text-teal-800">{{ $provider->specialties->pluck('name')->join(', ') ?: 'General consultation' }}</p>
                            <p class="text-sm text-slate-500">{{ $provider->department?->name ?? 'General department' }}</p>
                        </div>
                    </div>
                    <p class="mt-4 text-sm text-slate-600">{{ $provider->bio ?: 'Doctor profile details are being updated.' }}</p>
                    <div class="mt-auto pt-5">
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Next available</p>
                        <p class="mt-1 text-sm font-semibold text-slate-800">{{ $provider->next_available_slot?->starts_at->format('D, M j · g:i A') ?? 'No open slot found' }}</p>
                        <a href="{{ route('doctors.show', ['provider' => $provider, 'consultation_type' => $filters['consultation_type'] ?? null]) }}" class="mt-4 inline-flex w-full justify-center rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800">View profile & availability</a>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>
@endsection
