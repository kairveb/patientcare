@extends('layouts.hims')

@section('title', 'Convert ER Visit to Admission')

@section('content')
<div class="max-w-3xl space-y-6">
    <div>
        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-600">ER to inpatient</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-800">Convert ER Visit to Admission</h1>
        <p class="mt-1 text-sm text-slate-500">Patient: <span class="font-medium text-slate-700">{{ $visit->patient->full_name ?? '—' }}</span> · {{ $visit->patient->mrn ?? '' }}</p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <form method="POST" action="{{ route('emergency.admission.store', $visit) }}" class="space-y-5">
            @csrf

            <div class="grid gap-5 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">Chief complaint</label>
                    <input type="text" value="{{ old('reason', $visit->chief_complaint) }}" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-700" disabled>
                </div>

                <div>
                    <label for="attending_provider_id" class="mb-1.5 block text-sm font-medium text-slate-700">Attending provider <span class="hims-required" aria-hidden="true">*</span></label>
                    <select id="attending_provider_id" name="attending_provider_id" required class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
                        <option value="">Select provider</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider->id }}" {{ old('attending_provider_id') == $provider->id ? 'selected' : '' }}>
                                {{ $provider->full_name }}
                            </option>
                        @endforeach
                    </select>
                    @error('attending_provider_id')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="reason" class="mb-1.5 block text-sm font-medium text-slate-700">Admission reason</label>
                    <input id="reason" name="reason" type="text" value="{{ old('reason', $visit->chief_complaint) }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800" placeholder="Observation, inpatient evaluation...">
                </div>
                <div class="md:col-span-2">
                    <h2 class="text-sm font-semibold text-slate-800">Guarantor and LOA reference (data entry only)</h2>
                </div>
                <div>
                    <label for="guarantor_name" class="mb-1.5 block text-sm font-medium text-slate-700">Guarantor name</label>
                    <input id="guarantor_name" name="guarantor_name" value="{{ old('guarantor_name') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                    @error('guarantor_name')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="guarantor_relationship" class="mb-1.5 block text-sm font-medium text-slate-700">Relationship</label>
                    <input id="guarantor_relationship" name="guarantor_relationship" value="{{ old('guarantor_relationship') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                    @error('guarantor_relationship')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="guarantor_contact" class="mb-1.5 block text-sm font-medium text-slate-700">Guarantor contact</label>
                    <input id="guarantor_contact" name="guarantor_contact" value="{{ old('guarantor_contact') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                    @error('guarantor_contact')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="loa_reference" class="mb-1.5 block text-sm font-medium text-slate-700">LOA reference</label>
                    <input id="loa_reference" name="loa_reference" value="{{ old('loa_reference') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                    @error('loa_reference')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="coverage_code" class="mb-1.5 block text-sm font-medium text-slate-700">Insurance coverage code</label>
                    <input id="coverage_code" name="coverage_code" value="{{ old('coverage_code') }}" maxlength="80" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                    @error('coverage_code')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="loa_requested_amount" class="mb-1.5 block text-sm font-medium text-slate-700">LOA requested amount</label>
                    <input id="loa_requested_amount" name="loa_requested_amount" type="number" min="0.01" step="0.01" value="{{ old('loa_requested_amount') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                    @error('loa_requested_amount')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="flex flex-wrap gap-3 pt-2">
                <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                    Create Admission
                </button>
                <a href="{{ route('emergency.show', $visit) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
