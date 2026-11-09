@php
    $patient = $patient ?? null;
    $address = $address ?? null;
    $contact = $contact ?? null;

    $firstNameValue = old('first_name', $patient?->first_name ?? '');
    $middleNameValue = old('middle_name', $patient?->middle_name ?? '');
    $lastNameValue = old('last_name', $patient?->last_name ?? '');
    $suffixValue = old('suffix', $patient?->suffix ?? '');
    $dateOfBirthValue = old('date_of_birth', $patient?->date_of_birth?->format('Y-m-d') ?? '');
    $sexValue = old('sex', $patient?->sex ?? '');
    $civilStatusValue = old('civil_status', $patient?->civil_status ?? '');
    $nationalityValue = old('nationality', $patient?->nationality ?? '');
    $religionValue = old('religion', $patient?->religion ?? '');
    $occupationValue = old('occupation', $patient?->occupation ?? '');
    $philhealthNumberValue = old('philhealth_number', $patient?->philhealth_number ?? '');
    $seniorIdNumberValue = old('senior_id_number', $patient?->senior_id_number ?? '');
    $pwdIdNumberValue = old('pwd_id_number', $patient?->pwd_id_number ?? '');
    $hmoNameValue = old('hmo_name', $patient?->hmo_name ?? '');
    $hmoMemberIdValue = old('hmo_member_id', $patient?->hmo_member_id ?? '');
    $guarantorNameValue = old('guarantor_name', $patient?->guarantor_name ?? '');
    $guarantorRelationshipValue = old('guarantor_relationship', $patient?->guarantor_relationship ?? '');
    $guarantorContactValue = old('guarantor_contact', $patient?->guarantor_contact ?? '');
    $phoneValue = old('phone', $patient?->phone ?? '');
    $emailValue = old('email', $patient?->email ?? '');
    $addressLine1Value = old('address_line1', $address?->line1 ?? '');
    $addressRegionValue = old('address_region', $address?->region ?? '');
    $addressProvinceValue = old('address_province', $address?->province ?? '');
    $addressCityValue = old('address_city', $address?->city ?? '');
    $addressSubmunicipalityValue = old('address_submunicipality', $address?->submunicipality ?? '');
    $addressBarangayValue = old('address_barangay', $address?->barangay ?? '');
    $addressPostalValue = old('address_postal', $address?->postal_code ?? '');
    $allergiesValue = old('allergies', $patient?->allergies ?? '');
    $emergencyNameValue = old('emergency_name', $contact?->name ?? '');
    $emergencyRelationshipValue = old('emergency_relationship', $contact?->relationship ?? '');
    $emergencyPhoneValue = old('emergency_phone', $contact?->phone ?? '');
@endphp

<div class="grid gap-5 md:grid-cols-2">
    <div class="md:col-span-2">
        <h3 class="text-lg font-semibold text-slate-900">1. Essential Information</h3>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">First Name <span class="hims-required" aria-hidden="true">*</span></label>
        <input type="text" name="first_name" value="{{ $firstNameValue }}" required class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Middle Name</label>
        <input type="text" name="middle_name" value="{{ $middleNameValue }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Last Name <span class="hims-required" aria-hidden="true">*</span></label>
        <input type="text" name="last_name" value="{{ $lastNameValue }}" required class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Suffix</label>
        <input type="text" name="suffix" value="{{ $suffixValue }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Date of Birth <span class="hims-required" aria-hidden="true">*</span></label>
        <input type="date" name="date_of_birth" value="{{ $dateOfBirthValue }}" required class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Sex <span class="hims-required" aria-hidden="true">*</span></label>
        <select name="sex" required class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
            <option value="">Select</option>
            <option value="Male" {{ $sexValue === 'Male' ? 'selected' : '' }}>Male</option>
            <option value="Female" {{ $sexValue === 'Female' ? 'selected' : '' }}>Female</option>
            <option value="Other" {{ $sexValue === 'Other' ? 'selected' : '' }}>Other</option>
        </select>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Civil Status</label>
        <input type="text" name="civil_status" value="{{ $civilStatusValue }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Nationality</label>
        <input type="text" name="nationality" value="{{ $nationalityValue }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div class="md:col-span-2">
        <h3 class="text-lg font-semibold text-slate-900">Registration and coverage details</h3>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Religion</label>
        <input type="text" name="religion" value="{{ $religionValue }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Occupation</label>
        <input type="text" name="occupation" value="{{ $occupationValue }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">PhilHealth number</label>
        <input type="text" name="philhealth_number" value="{{ $philhealthNumberValue }}" inputmode="numeric" pattern="[0-9]{12}" maxlength="12" data-philhealth-input class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Senior citizen ID number</label>
        <input type="text" name="senior_id_number" value="{{ $seniorIdNumberValue }}" maxlength="100" autocomplete="off" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">PWD ID number</label>
        <input type="text" name="pwd_id_number" value="{{ $pwdIdNumberValue }}" maxlength="100" autocomplete="off" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">HMO name</label>
        <input type="text" name="hmo_name" value="{{ $hmoNameValue }}" maxlength="150" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">HMO member ID</label>
        <input type="text" name="hmo_member_id" value="{{ $hmoMemberIdValue }}" maxlength="100" autocomplete="off" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Guarantor name</label>
        <input type="text" name="guarantor_name" value="{{ $guarantorNameValue }}" maxlength="150" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Guarantor relationship</label>
        <input type="text" name="guarantor_relationship" value="{{ $guarantorRelationshipValue }}" maxlength="100" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Guarantor contact</label>
        <input type="tel" name="guarantor_contact" value="{{ $guarantorContactValue }}" data-phone-input class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800" inputmode="numeric" pattern="^(09\d{9}|\+639\d{9})$" placeholder="09XXXXXXXXX or +639XXXXXXXXX">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Contact Number</label>
        <input type="tel" name="phone" value="{{ $phoneValue }}" data-phone-input class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800" inputmode="numeric" pattern="^(09\d{9}|\+639\d{9})$" placeholder="09XXXXXXXXX or +639XXXXXXXXX">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Email</label>
        <input type="email" name="email" value="{{ $emailValue }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div class="md:col-span-2">
        <h3 class="text-lg font-semibold text-slate-900">2. Address</h3>
    </div>

    <div class="md:col-span-2">
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Street Address</label>
        <input type="text" name="address_line1" value="{{ $addressLine1Value }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800" placeholder="House number, street, subdivision">
    </div>

    <div class="md:col-span-2">
        <x-philippine-address-fields
            region-name="address_region"
            province-name="address_province"
            city-name="address_city"
            submunicipality-name="address_submunicipality"
            barangay-name="address_barangay"
            region-code-name="address_region_psgc_code"
            province-code-name="address_province_psgc_code"
            city-code-name="address_city_psgc_code"
            submunicipality-code-name="address_submunicipality_psgc_code"
            barangay-code-name="address_barangay_psgc_code"
            :region-value="$addressRegionValue"
            :province-value="$addressProvinceValue"
            :city-value="$addressCityValue"
            :submunicipality-value="$addressSubmunicipalityValue"
            :barangay-value="$addressBarangayValue"
            :region-code-value="$address?->region_psgc_code"
            :province-code-value="$address?->province_psgc_code"
            :city-code-value="$address?->city_psgc_code"
            :submunicipality-code-value="$address?->submunicipality_psgc_code"
            :barangay-code-value="$address?->barangay_psgc_code"
        />
    </div>

    <div class="md:col-span-2">
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Postal Code</label>
        <input type="text" name="address_postal" value="{{ $addressPostalValue }}" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" data-digits-only class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div class="md:col-span-2">
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Allergies / Alerts</label>
        <textarea name="allergies" rows="3" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">{{ $allergiesValue }}</textarea>
    </div>

    <div class="md:col-span-2">
        <h3 class="text-lg font-semibold text-slate-900">Emergency Contact</h3>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Emergency Contact Name</label>
        <input type="text" name="emergency_name" value="{{ $emergencyNameValue }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Emergency Contact Relationship</label>
        <input type="text" name="emergency_relationship" value="{{ $emergencyRelationshipValue }}" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800">
    </div>

    <div class="md:col-span-2">
        <label class="mb-1.5 block text-sm font-medium text-slate-700">Emergency Contact Phone</label>
        <input type="tel" name="emergency_phone" value="{{ $emergencyPhoneValue }}" data-phone-input class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800" inputmode="numeric" pattern="^(09\d{9}|\+639\d{9})$" placeholder="09XXXXXXXXX or +639XXXXXXXXX">
    </div>
</div>
