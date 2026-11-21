@extends('layouts.hims')

@section('title', 'New Admission')

@section('content')
<div class="max-w-2xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-800">New Admission</h1>
        <p class="text-sm text-slate-500 mt-1">Inpatient and Bed Management System (IBMS)</p>
    </div>

    <form method="POST" action="{{ route('admissions.store') }}" class="bg-white rounded-xl border border-slate-200 p-6 space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Patient <span class="hims-required" aria-hidden="true">*</span></label>
            <select name="patient_id" required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                <option value="">Select patient</option>
                @foreach ($patients as $patient)
                    <option value="{{ $patient->id }}">{{ $patient->full_name }} — {{ $patient->mrn }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Attending Provider <span class="hims-required" aria-hidden="true">*</span></label>
            <select name="attending_provider_id" required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                <option value="">Select provider</option>
                @foreach ($providers as $provider)
                    <option value="{{ $provider->id }}" {{ old('attending_provider_id') == $provider->id ? 'selected' : '' }}>{{ $provider->full_name }}</option>
                @endforeach
            </select>
            @error('attending_provider_id')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Reason for Admission</label>
            <textarea name="reason" rows="3" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"></textarea>
        </div>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="guarantor_name" class="mb-1 block text-sm font-medium text-slate-700">Guarantor name</label>
                <input id="guarantor_name" name="guarantor_name" value="{{ old('guarantor_name') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('guarantor_name')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="guarantor_relationship" class="mb-1 block text-sm font-medium text-slate-700">Relationship</label>
                <input id="guarantor_relationship" name="guarantor_relationship" value="{{ old('guarantor_relationship') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('guarantor_relationship')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="guarantor_contact" class="mb-1 block text-sm font-medium text-slate-700">Guarantor contact</label>
                <input id="guarantor_contact" name="guarantor_contact" value="{{ old('guarantor_contact') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('guarantor_contact')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="loa_reference" class="mb-1 block text-sm font-medium text-slate-700">LOA reference</label>
                <input id="loa_reference" name="loa_reference" value="{{ old('loa_reference') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('loa_reference')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="coverage_code" class="mb-1 block text-sm font-medium text-slate-700">Insurance coverage code</label>
                <input id="coverage_code" name="coverage_code" value="{{ old('coverage_code') }}" maxlength="80" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('coverage_code')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="loa_requested_amount" class="mb-1 block text-sm font-medium text-slate-700">LOA requested amount</label>
                <input id="loa_requested_amount" name="loa_requested_amount" type="number" min="0.01" step="0.01" value="{{ old('loa_requested_amount') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('loa_requested_amount')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-6 py-2.5 text-sm font-medium bg-teal-600 text-white rounded-lg hover:bg-teal-700">Create Admission Request</button>
            <a href="{{ route('admissions.index') }}" class="px-6 py-2.5 text-sm border border-slate-300 rounded-lg hover:bg-slate-50">Cancel</a>
        </div>
    </form>
</div>
@endsection
