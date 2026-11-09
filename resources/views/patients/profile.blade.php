@extends('layouts.hims')

@section('title', 'My Patient Profile')

@section('content')
<div class="max-w-5xl space-y-6">
    <div class="flex items-center justify-between gap-3">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.3em] text-teal-600">Patient portal</p>
            <h1 class="mt-2 text-3xl font-bold text-slate-900">Pre-registration profile</h1>
        </div>

        @if ($patient->lookup_code)
            <div class="rounded-xl border border-teal-200 bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-700">
                Patient lookup code: {{ $patient->lookup_code }}
            </div>
        @endif
    </div>

    <form method="POST" action="{{ route('patients.profile.save') }}" class="space-y-6">
        @csrf

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold text-slate-800">Personal details</h2>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">First name</label>
                    <input type="text" name="first_name" value="{{ old('first_name', $patient->first_name) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2" required>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Middle name</label>
                    <input type="text" name="middle_name" value="{{ old('middle_name', $patient->middle_name) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Last name</label>
                    <input type="text" name="last_name" value="{{ old('last_name', $patient->last_name) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2" required>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Date of birth</label>
                    <input type="date" name="date_of_birth" value="{{ old('date_of_birth', optional($patient->date_of_birth)->format('Y-m-d')) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2" required>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Sex</label>
                    <select name="sex" class="w-full rounded-lg border border-slate-300 px-3 py-2" required>
                        <option value="">Select</option>
                        <option value="Male" {{ old('sex', $patient->sex) === 'Male' ? 'selected' : '' }}>Male</option>
                        <option value="Female" {{ old('sex', $patient->sex) === 'Female' ? 'selected' : '' }}>Female</option>
                        <option value="Other" {{ old('sex', $patient->sex) === 'Other' ? 'selected' : '' }}>Other</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Phone</label>
                    <input type="tel" name="phone" value="{{ old('phone', $patient->phone) }}" data-phone-input class="w-full rounded-lg border border-slate-300 px-3 py-2" inputmode="numeric" pattern="^(09\d{9}|\+639\d{9})$" placeholder="09XXXXXXXXX or +639XXXXXXXXX">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Email</label>
                    <input type="email" name="email" value="{{ old('email', $patient->email ?? auth()->user()->email) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Civil status</label>
                    <select name="civil_status" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        <option value="">Select civil status</option>
                        @foreach (['Single', 'Married', 'Widowed', 'Separated', 'Annulled/Divorced'] as $civilStatus)
                            <option value="{{ $civilStatus }}" @selected(old('civil_status', $patient->civil_status) === $civilStatus)>{{ $civilStatus }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Nationality</label>
                    <input type="text" name="nationality" value="{{ old('nationality') ?: ($patient->nationality ?: 'Filipino') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Religion</label>
                    <input type="text" name="religion" value="{{ old('religion', $patient->religion) }}" maxlength="100" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Occupation</label>
                    <input type="text" name="occupation" value="{{ old('occupation', $patient->occupation) }}" maxlength="150" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">PhilHealth number</label>
                    <input type="text" name="philhealth_number" value="{{ old('philhealth_number', $patient->philhealth_number) }}" maxlength="30" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Senior citizen ID number</label>
                    <input type="text" name="senior_id_number" value="{{ old('senior_id_number', $patient->senior_id_number) }}" maxlength="100" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">PWD ID number</label>
                    <input type="text" name="pwd_id_number" value="{{ old('pwd_id_number', $patient->pwd_id_number) }}" maxlength="100" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">HMO name</label>
                    <input type="text" name="hmo_name" value="{{ old('hmo_name', $patient->hmo_name) }}" maxlength="150" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">HMO member ID</label>
                    <input type="text" name="hmo_member_id" value="{{ old('hmo_member_id', $patient->hmo_member_id) }}" maxlength="100" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Guarantor name</label>
                    <input type="text" name="guarantor_name" value="{{ old('guarantor_name', $patient->guarantor_name) }}" maxlength="150" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Guarantor relationship</label>
                    <input type="text" name="guarantor_relationship" value="{{ old('guarantor_relationship', $patient->guarantor_relationship) }}" maxlength="100" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Guarantor contact</label>
                    <input type="tel" name="guarantor_contact" value="{{ old('guarantor_contact', $patient->guarantor_contact) }}" data-phone-input class="w-full rounded-lg border border-slate-300 px-3 py-2" inputmode="numeric" pattern="^(09\d{9}|\+639\d{9})$" placeholder="09XXXXXXXXX or +639XXXXXXXXX">
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold text-slate-800">Address</h2>

            @php
                $address = $patient->addresses->first();
            @endphp

            <div class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Street address</label>
                    <input type="text" name="address[line1]" value="{{ old('address.line1', $address->line1 ?? '') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <x-philippine-address-fields
                    :region-name="'address[region]'"
                    :province-name="'address[province]'"
                    :city-name="'address[city]'"
                    :submunicipality-name="'address[submunicipality]'"
                    :barangay-name="'address[barangay]'"
                    :region-code-name="'address[region_psgc_code]'"
                    :province-code-name="'address[province_psgc_code]'"
                    :city-code-name="'address[city_psgc_code]'"
                    :submunicipality-code-name="'address[submunicipality_psgc_code]'"
                    :barangay-code-name="'address[barangay_psgc_code]'"
                    :region-value="old('address.region', $address->region ?? '')"
                    :province-value="old('address.province', $address->province ?? '')"
                    :city-value="old('address.city', $address->city ?? '')"
                    :submunicipality-value="old('address.submunicipality', $address->submunicipality ?? '')"
                    :barangay-value="old('address.barangay', $address->barangay ?? '')"
                    :region-code-value="old('address.region_psgc_code', $address->region_psgc_code ?? '')"
                    :province-code-value="old('address.province_psgc_code', $address->province_psgc_code ?? '')"
                    :city-code-value="old('address.city_psgc_code', $address->city_psgc_code ?? '')"
                    :submunicipality-code-value="old('address.submunicipality_psgc_code', $address->submunicipality_psgc_code ?? '')"
                    :barangay-code-value="old('address.barangay_psgc_code', $address->barangay_psgc_code ?? '')"
                />

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Postal code</label>
                    <input type="text" name="address[postal_code]" value="{{ old('address.postal_code', $address->postal_code ?? '') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold text-slate-800">Emergency contact</h2>

            @php
                $contact = $patient->emergencyContacts->first();
            @endphp

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Name</label>
                    <input type="text" name="emergency_contact[name]" value="{{ old('emergency_contact.name', $contact->name ?? '') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Relationship</label>
                    <input type="text" name="emergency_contact[relationship]" value="{{ old('emergency_contact.relationship', $contact->relationship ?? '') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Phone</label>
                    <input type="tel" name="emergency_contact[phone]" value="{{ old('emergency_contact.phone', $contact->phone ?? '') }}" data-phone-input class="w-full rounded-lg border border-slate-300 px-3 py-2" inputmode="numeric" pattern="^(09\d{9}|\+639\d{9})$" placeholder="09XXXXXXXXX or +639XXXXXXXXX">
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold text-slate-800">Medical alerts</h2>
            <textarea name="allergies" rows="4" class="w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('allergies', $patient->allergies) }}</textarea>
        </div>

        @unless ($hasDataPrivacyConsent)
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
                <label class="flex items-start gap-3 text-sm text-amber-950">
                    <input type="checkbox" name="data_privacy_consent" value="1" required @checked(old('data_privacy_consent')) class="mt-1 rounded border-amber-400">
                    <span><strong>{{ $consentTypes['DATA_PRIVACY']['title'] }} (v{{ $consentTypes['DATA_PRIVACY']['version'] }})</strong><br>{{ $consentTypes['DATA_PRIVACY']['text'] }}</span>
                </label>
                @error('data_privacy_consent')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
        @endunless

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('dashboard') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700">Cancel</a>
            <button type="submit" class="rounded-lg bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-700">
                Save pre-registration details
            </button>
        </div>
    </form>

    <div class="rounded-2xl border border-slate-200 bg-white p-6">
        <h2 class="mb-2 text-lg font-semibold text-slate-800">Identity document images</h2>
        <p class="mb-4 text-sm text-slate-600">Upload an image of an ID for Registration Staff review. Images are stored as clinical document references.</p>
        <form method="POST" action="{{ route('patients.profile.identity-documents.store') }}" enctype="multipart/form-data" class="grid gap-4 md:grid-cols-3">
            @csrf
            <select name="identifier_type" required class="rounded-lg border border-slate-300 px-3 py-2">
                <option value="">Select ID type</option>
                <option value="philhealth_number">PhilHealth ID</option>
                <option value="senior_id_number">Senior citizen ID</option>
                <option value="pwd_id_number">PWD ID</option>
                <option value="hmo_member_id">HMO member ID</option>
            </select>
            <input type="file" name="document" accept="image/*" required class="rounded-lg border border-slate-300 px-3 py-2">
            <button type="submit" class="rounded-lg bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-700">Upload ID image</button>
        </form>
        @error('document')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
        @error('identifier_type')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
        @php($identityDocuments = $patient->clinicalDocuments->whereNotNull('identifier_type'))
        @if ($identityDocuments->isNotEmpty())
            <ul class="mt-4 space-y-2 text-sm text-slate-700">
                @foreach ($identityDocuments as $document)
                    <li>{{ $document->name }} — uploaded {{ $document->created_at->format('M d, Y') }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endsection
