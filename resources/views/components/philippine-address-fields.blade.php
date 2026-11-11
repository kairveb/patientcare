@props([
    'regionName' => 'address_region',
    'provinceName' => 'address_province',
    'cityName' => 'address_city',
    'submunicipalityName' => 'address_submunicipality',
    'barangayName' => 'address_barangay',
    'regionCodeName' => 'address_region_code',
    'provinceCodeName' => 'address_province_code',
    'cityCodeName' => 'address_city_code',
    'submunicipalityCodeName' => 'address_submunicipality_code',
    'barangayCodeName' => 'address_barangay_code',
    'regionValue' => null,
    'provinceValue' => null,
    'cityValue' => null,
    'submunicipalityValue' => null,
    'barangayValue' => null,
    'regionCodeValue' => null,
    'provinceCodeValue' => null,
    'cityCodeValue' => null,
    'submunicipalityCodeValue' => null,
    'barangayCodeValue' => null,
])

@php
    $componentId = 'phil-address-' . uniqid();
    $fieldNames = [
        'region' => $regionName,
        'province' => $provinceName,
        'city' => $cityName,
        'submunicipality' => $submunicipalityName,
        'barangay' => $barangayName,
    ];
    $codeNames = [
        'region' => $regionCodeName,
        'province' => $provinceCodeName,
        'city' => $cityCodeName,
        'submunicipality' => $submunicipalityCodeName,
        'barangay' => $barangayCodeName,
    ];
    $values = [
        'region' => old(str_replace(['[', ']'], ['.', ''], $regionName), $regionValue ?? ''),
        'province' => old(str_replace(['[', ']'], ['.', ''], $provinceName), $provinceValue ?? ''),
        'city' => old(str_replace(['[', ']'], ['.', ''], $cityName), $cityValue ?? ''),
        'submunicipality' => old(str_replace(['[', ']'], ['.', ''], $submunicipalityName), $submunicipalityValue ?? ''),
        'barangay' => old(str_replace(['[', ']'], ['.', ''], $barangayName), $barangayValue ?? ''),
    ];
    $codes = [
        'region' => old(str_replace(['[', ']'], ['.', ''], $regionCodeName), $regionCodeValue ?? ''),
        'province' => old(str_replace(['[', ']'], ['.', ''], $provinceCodeName), $provinceCodeValue ?? ''),
        'city' => old(str_replace(['[', ']'], ['.', ''], $cityCodeName), $cityCodeValue ?? ''),
        'submunicipality' => old(str_replace(['[', ']'], ['.', ''], $submunicipalityCodeName), $submunicipalityCodeValue ?? ''),
        'barangay' => old(str_replace(['[', ']'], ['.', ''], $barangayCodeName), $barangayCodeValue ?? ''),
    ];
@endphp

<div id="{{ $componentId }}" class="grid grid-cols-1 gap-4 md:grid-cols-2" data-philippine-address-root
    data-endpoint="{{ route('api.v1.address-data.regions', [], false) }}"
    data-initial-codes="{{ json_encode($codes) }}"
    data-legacy-names="{{ json_encode($values) }}">
    @foreach (['region' => 'Region', 'province' => 'Province', 'city' => 'City / Municipality', 'submunicipality' => 'Manila District', 'barangay' => 'Barangay'] as $level => $label)
        <div data-address-wrapper="{{ $level }}" @if ($level === 'submunicipality') class="hidden" @endif>
            <label for="{{ $componentId }}-{{ $level }}" class="mb-1 block text-sm font-medium text-slate-700">{{ $label }}</label>
            <select id="{{ $componentId }}-{{ $level }}" data-address-select="{{ $level }}"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:ring-2 focus:ring-teal-100"
                @if ($level !== 'region') disabled @endif>
                <option value="">Select {{ strtolower($label) }}</option>
            </select>
            <input type="hidden" name="{{ $fieldNames[$level] }}" value="{{ $codes[$level] ? $values[$level] : '' }}" data-address-name="{{ $level }}">
            <input type="hidden" name="{{ $codeNames[$level] }}" value="{{ $codes[$level] }}" data-address-code="{{ $level }}">
        </div>
    @endforeach
    <div class="md:col-span-2 hidden" data-address-legacy>
        <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            Existing address text: <span data-address-legacy-text></span>. Select a location above only if you want to replace it.
        </p>
    </div>
    <p class="hidden md:col-span-2 text-sm text-red-700" data-address-error role="alert"></p>
</div>

@once
    @push('scripts')
        <script>
            (() => {
                const fetchRows = async (endpoint, path) => {
                    const response = await fetch(`${endpoint}/${path}`, {
                        cache: 'force-cache',
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    });
                    if (!response.ok) {
                        const payload = await response.json().catch(() => null);
                        throw new Error(payload?.message || 'Address options could not be loaded. Please try again later.');
                    }
                    const payload = await response.json();
                    if (!payload.success || !Array.isArray(payload.data)) {
                        throw new Error('Address data response is invalid.');
                    }
                    return payload.data;
                };

                const initAddressGroup = async (root) => {
                    const levels = ['region', 'province', 'city', 'submunicipality', 'barangay'];
                    const selects = Object.fromEntries(levels.map((level) => [
                        level,
                        root.querySelector(`[data-address-select="${level}"]`),
                    ]));
                    const names = Object.fromEntries(levels.map((level) => [
                        level,
                        root.querySelector(`[data-address-name="${level}"]`),
                    ]));
                    const codes = Object.fromEntries(levels.map((level) => [
                        level,
                        root.querySelector(`[data-address-code="${level}"]`),
                    ]));
                    const wrappers = Object.fromEntries(levels.map((level) => [
                        level,
                        root.querySelector(`[data-address-wrapper="${level}"]`),
                    ]));
                    const endpoint = root.dataset.endpoint.replace(/\/regions\/?$/, '');
                    const initialCodes = JSON.parse(root.dataset.initialCodes || '{}');
                    const legacyNames = JSON.parse(root.dataset.legacyNames || '{}');
                    const error = root.querySelector('[data-address-error]');
                    const legacy = root.querySelector('[data-address-legacy]');

                    const setOptions = (select, rows, selected = '') => {
                        select.replaceChildren(new Option(`Select ${select.dataset.addressSelect}`, ''));
                        rows.forEach((row) => select.add(new Option(row.name, row.psgc_code)));
                        select.value = rows.some((row) => row.psgc_code === selected) ? selected : '';
                        select.disabled = false;
                    };
                    const addDirectRegionChoice = (select, selected = '') => {
                        select.add(new Option('No province (city is directly under region)', '__region__'));
                        if (selected === '__region__') select.value = selected;
                    };
                    const clearChildren = (startAt) => {
                        const start = levels.indexOf(startAt);
                        levels.slice(start).forEach((level) => {
                            selects[level].replaceChildren(new Option(`Select ${level}`, ''));
                            selects[level].disabled = true;
                            names[level].value = '';
                            codes[level].value = '';
                            if (level === 'submunicipality') wrappers[level].classList.add('hidden');
                        });
                    };
                    const setSelected = (level, row) => {
                        codes[level].value = row.psgc_code;
                        names[level].value = row.name;
                    };
                    const showError = (message) => {
                        error.textContent = message;
                        error.classList.toggle('hidden', !message);
                    };
                    const loadBarangaysFor = async (parentCode, parentLevel, selectedCode = '') => {
                        const path = parentLevel === 'submunicipality'
                            ? `sub-municipalities/${parentCode}/barangays`
                            : `cities/${parentCode}/barangays`;
                        const rows = await fetchRows(endpoint, path);
                        setOptions(selects.barangay, rows, selectedCode);
                        if (selects.barangay.value) {
                            setSelected('barangay', rows.find((row) => row.psgc_code === selects.barangay.value));
                        }
                    };
                    const loadCities = async (parentCode, parentLevel, selectedCode = '') => {
                        const path = parentLevel === 'region'
                            ? `regions/${parentCode}/cities`
                            : `provinces/${parentCode}/cities`;
                        const rows = await fetchRows(endpoint, path);
                        setOptions(selects.city, rows, selectedCode);
                        if (selects.city.value) {
                            setSelected('city', rows.find((row) => row.psgc_code === selects.city.value));
                            await loadSubmunicipalities(selects.city.value, initialCodes.submunicipality, initialCodes.barangay);
                        }
                    };
                    const loadSubmunicipalities = async (cityCode, selectedCode = '', barangayCode = '') => {
                        const [submunicipalities, directBarangays] = await Promise.all([
                            fetchRows(endpoint, `cities/${cityCode}/sub-municipalities`),
                            fetchRows(endpoint, `cities/${cityCode}/barangays`),
                        ]);
                        if (submunicipalities.length) {
                            wrappers.submunicipality.classList.remove('hidden');
                            setOptions(selects.submunicipality, submunicipalities, selectedCode);
                            if (selects.submunicipality.value) {
                                setSelected('submunicipality', submunicipalities.find((row) => row.psgc_code === selects.submunicipality.value));
                                await loadBarangaysFor(selects.submunicipality.value, 'submunicipality', barangayCode);
                            }
                            return;
                        }
                        wrappers.submunicipality.classList.add('hidden');
                        setOptions(selects.barangay, directBarangays, barangayCode);
                        if (selects.barangay.value) {
                            setSelected('barangay', directBarangays.find((row) => row.psgc_code === selects.barangay.value));
                        }
                    };

                    if (Object.values(legacyNames).some(Boolean) && !Object.values(initialCodes).some(Boolean)) {
                        legacy.querySelector('[data-address-legacy-text]').textContent =
                            [legacyNames.region, legacyNames.province, legacyNames.city, legacyNames.submunicipality, legacyNames.barangay]
                                .filter(Boolean).join(', ');
                        legacy.classList.remove('hidden');
                    }

                    try {
                        const regions = await fetchRows(endpoint, 'regions');
                        setOptions(selects.region, regions, initialCodes.region);
                        if (selects.region.value) {
                            setSelected('region', regions.find((row) => row.psgc_code === selects.region.value));
                            const provinces = await fetchRows(endpoint, `regions/${selects.region.value}/provinces`);
                            if (provinces.length) {
                                wrappers.province.classList.remove('hidden');
                                setOptions(selects.province, provinces, initialCodes.province);
                                if (selects.province.value) {
                                    setSelected('province', provinces.find((row) => row.psgc_code === selects.province.value));
                                    await loadCities(selects.province.value, 'province', initialCodes.city);
                                } else {
                                    const directCities = await fetchRows(endpoint, `regions/${selects.region.value}/cities`);
                                    if (directCities.length) addDirectRegionChoice(selects.province, '__region__');
                                    if (initialCodes.city && directCities.some((row) => row.psgc_code === initialCodes.city)) {
                                        setSelected('province', { psgc_code: '', name: '' });
                                        await loadCities(selects.region.value, 'region', initialCodes.city);
                                    }
                                }
                            } else {
                                wrappers.province.classList.add('hidden');
                                await loadCities(selects.region.value, 'region', initialCodes.city);
                            }
                        }

                        selects.region.addEventListener('change', async () => {
                            showError('');
                            clearChildren('province');
                            names.region.value = '';
                            codes.region.value = '';
                            wrappers.province.classList.remove('hidden');
                            wrappers.submunicipality.classList.add('hidden');
                            const region = regions.find((row) => row.psgc_code === selects.region.value);
                            if (!region) return;
                            setSelected('region', region);
                            try {
                                const provinces = await fetchRows(endpoint, `regions/${region.psgc_code}/provinces`);
                                if (provinces.length) {
                                    setOptions(selects.province, provinces);
                                    const directCities = await fetchRows(endpoint, `regions/${region.psgc_code}/cities`);
                                    if (directCities.length) addDirectRegionChoice(selects.province);
                                } else {
                                    wrappers.province.classList.add('hidden');
                                    await loadCities(region.psgc_code, 'region');
                                }
                            } catch (exception) {
                                showError(exception.message);
                            }
                        });

                        selects.province.addEventListener('change', async () => {
                            showError('');
                            clearChildren('city');
                            names.province.value = '';
                            codes.province.value = '';
                            const province = Array.from(selects.province.options).find((row) => row.value === selects.province.value);
                            if (!province || !province.value) return;
                            if (province.value === '__region__') {
                                setSelected('province', { psgc_code: '', name: '' });
                                try {
                                    await loadCities(selects.region.value, 'region');
                                } catch (exception) {
                                    showError(exception.message);
                                }
                                return;
                            }
                            setSelected('province', { psgc_code: province.value, name: province.text });
                            try {
                                await loadCities(province.value, 'province');
                            } catch (exception) {
                                showError(exception.message);
                            }
                        });

                        selects.city.addEventListener('change', async () => {
                            showError('');
                            clearChildren('submunicipality');
                            names.city.value = '';
                            codes.city.value = '';
                            wrappers.submunicipality.classList.add('hidden');
                            const city = Array.from(selects.city.options).find((row) => row.value === selects.city.value);
                            if (!city || !city.value) return;
                            setSelected('city', { psgc_code: city.value, name: city.text });
                            try {
                                await loadSubmunicipalities(city.value);
                            } catch (exception) {
                                showError(exception.message);
                            }
                        });

                        selects.submunicipality.addEventListener('change', async () => {
                            showError('');
                            clearChildren('barangay');
                            names.submunicipality.value = '';
                            codes.submunicipality.value = '';
                            const submunicipality = Array.from(selects.submunicipality.options)
                                .find((row) => row.value === selects.submunicipality.value);
                            if (!submunicipality || !submunicipality.value) return;
                            setSelected('submunicipality', {
                                psgc_code: submunicipality.value,
                                name: submunicipality.text,
                            });
                            try {
                                await loadBarangaysFor(submunicipality.value, 'submunicipality');
                            } catch (exception) {
                                showError(exception.message);
                            }
                        });

                        selects.barangay.addEventListener('change', () => {
                            const barangay = Array.from(selects.barangay.options).find((row) => row.value === selects.barangay.value);
                            if (!barangay || !barangay.value) {
                                names.barangay.value = '';
                                codes.barangay.value = '';
                                return;
                            }
                            setSelected('barangay', { psgc_code: barangay.value, name: barangay.text });
                        });
                    } catch (exception) {
                        showError(exception.message);
                    }
                };

                document.querySelectorAll('[data-philippine-address-root]').forEach(initAddressGroup);
            })();
        </script>
    @endpush
@endonce
