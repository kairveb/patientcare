<?php

namespace App\Services;

use RuntimeException;
use Illuminate\Validation\ValidationException;

class PhilippineAddressDataset
{
    public function regions(): array
    {
        return $this->byLevel('region');
    }

    public function provinces(string $regionCode): array
    {
        $this->requireLevel($regionCode, 'region');

        return $this->children($regionCode, ['province']);
    }

    public function citiesByRegion(string $regionCode): array
    {
        $this->requireLevel($regionCode, 'region');

        return $this->children($regionCode, ['city', 'municipality']);
    }

    public function citiesByProvince(string $provinceCode): array
    {
        $this->requireLevel($provinceCode, 'province');

        return $this->children($provinceCode, ['city', 'municipality']);
    }

    public function submunicipalities(string $cityCode): array
    {
        $this->requireLevel($cityCode, 'city');

        return $this->children($cityCode, ['submunicipality']);
    }

    public function barangays(string $parentCode): array
    {
        $parent = $this->row($parentCode);
        if (! in_array($parent['geographic_level'], ['city', 'municipality', 'submunicipality'], true)) {
            abort(404);
        }

        return $this->children($parentCode, ['barangay']);
    }

    public function row(string $code): array
    {
        return $this->findRow($code) ?? abort(404);
    }

    public function children(string $parentCode, array $levels): array
    {
        $rows = [];
        foreach ($this->rows() as $row) {
            if ($row['parent_psgc_code'] === $parentCode
                && in_array($row['geographic_level'], $levels, true)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function descendants(): array
    {
        $rows = [];
        foreach ($this->rows() as $row) {
            $rows[$row['psgc_code']] = $row;
        }

        return $rows;
    }

    public function validateSelection(array $selection): array
    {
        $levels = [
            'region' => 'region',
            'province' => 'province',
            'city' => ['city', 'municipality'],
            'submunicipality' => 'submunicipality',
            'barangay' => 'barangay',
        ];
        $hasLocation = false;
        foreach (array_keys($levels) as $level) {
            $hasLocation = $hasLocation
                || filled($selection[$level.'_psgc_code'] ?? null)
                || filled($selection[$level] ?? null);
        }

        if (! $hasLocation) {
            return [];
        }

        $selectedRows = [];
        foreach ($levels as $level => $allowedLevels) {
            $code = trim((string) ($selection[$level.'_psgc_code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $row = $this->findRow($code);
            if ($row === null) {
                $this->invalid($level, 'The selected PSGC code is not in the official dataset.');
            }
            $allowedLevels = (array) $allowedLevels;
            if (! in_array($row['geographic_level'], $allowedLevels, true)) {
                $this->invalid($level, 'The selected address level is invalid.');
            }
            $submittedName = trim((string) ($selection[$level] ?? ''));
            if ($submittedName !== '' && $submittedName !== $row['name']) {
                $this->invalid($level, 'The selected address name does not match its PSGC code.');
            }
            $selectedRows[$level] = $row;
        }

        foreach (['region', 'city', 'barangay'] as $required) {
            if (! isset($selectedRows[$required])) {
                $this->invalid($required, 'Select a complete PSGC address.');
            }
        }

        $region = $selectedRows['region'];
        $province = $selectedRows['province'] ?? null;
        $city = $selectedRows['city'];
        $submunicipality = $selectedRows['submunicipality'] ?? null;
        $barangay = $selectedRows['barangay'];

        if (($region['geographic_level'] ?? null) !== 'region'
            || ($province && $province['parent_psgc_code'] !== $region['psgc_code'])) {
            $this->invalid('province', 'The province does not belong to the selected region.');
        }

        $expectedCityParent = $province['psgc_code'] ?? $region['psgc_code'];
        if ($city['parent_psgc_code'] !== $expectedCityParent) {
            $this->invalid('city', 'The city or municipality does not belong to the selected region and province.');
        }

        $submunicipalities = $this->children($city['psgc_code'], ['submunicipality']);
        if ($submunicipalities !== []) {
            if (! $submunicipality || $submunicipality['parent_psgc_code'] !== $city['psgc_code']) {
                $this->invalid('submunicipality', 'Select a district belonging to the selected city.');
            }
        } elseif ($submunicipality) {
            $this->invalid('submunicipality', 'The selected district does not belong to this city.');
        }

        $expectedBarangayParent = $submunicipality['psgc_code'] ?? $city['psgc_code'];
        if ($barangay['parent_psgc_code'] !== $expectedBarangayParent) {
            $this->invalid('barangay', 'The barangay does not belong to the selected city or district.');
        }

        return collect($selectedRows)
            ->mapWithKeys(static fn (array $row, string $level): array => [
                $level => $row['name'],
                $level.'_psgc_code' => $row['psgc_code'],
            ])
            ->all();
    }

    public function hasLocationChanged(?object $currentAddress, array $submitted): bool
    {
        foreach (['region', 'province', 'city', 'submunicipality', 'barangay'] as $level) {
            foreach ([$level, $level.'_psgc_code'] as $field) {
                if (! array_key_exists($field, $submitted)) {
                    continue;
                }
                $currentValue = trim((string) ($currentAddress?->{$field} ?? ''));
                $submittedValue = trim((string) ($submitted[$field] ?? ''));
                if ($currentValue !== $submittedValue) {
                    return true;
                }
            }
        }

        return false;
    }

    public function withoutEmptySelection(array $selection): array
    {
        foreach (['region', 'province', 'city', 'submunicipality', 'barangay'] as $level) {
            if (blank($selection[$level] ?? null) && blank($selection[$level.'_psgc_code'] ?? null)) {
                unset($selection[$level], $selection[$level.'_psgc_code']);
            }
        }

        return $selection;
    }

    private function byLevel(string $level): array
    {
        $rows = [];
        foreach ($this->rows() as $row) {
            if ($row['geographic_level'] === $level) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function requireLevel(string $code, string $level): array
    {
        $row = $this->row($code);
        abort_unless($row['geographic_level'] === $level, 404);

        return $row;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $field => [$message],
        ]);
    }

    private function findRow(string $code): ?array
    {
        foreach ($this->rows() as $row) {
            if ($row['psgc_code'] === $code) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Read each CSV row on demand instead of retaining a multi-index cache of the whole dataset.
     */
    private function rows(): \Generator
    {
        $path = resource_path('data/psgc_2026_q2.csv');
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The local PSGC dataset is missing or unreadable.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The local PSGC dataset could not be opened.');
        }

        try {
            $header = fgetcsv($handle);
            if ($header !== ['psgc_code', 'name', 'geographic_level', 'parent_psgc_code', 'city_class']) {
                throw new RuntimeException('The local PSGC dataset has an unexpected CSV header.');
            }

            while (($values = fgetcsv($handle)) !== false) {
                if (count($values) !== count($header)) {
                    throw new RuntimeException('The local PSGC dataset contains a malformed row.');
                }

                [$code, $name, $level, $parentCode, $cityClass] = $values;
                if (! preg_match('/^\d{10}$/', $code)
                    || $name === ''
                    || ! in_array($level, ['region', 'province', 'city', 'municipality', 'submunicipality', 'barangay'], true)
                    || ($level === 'region' && $parentCode !== '')
                    || ($level !== 'region' && $parentCode === '')) {
                    throw new RuntimeException('The local PSGC dataset contains an invalid geographic row.');
                }

                yield [
                    'psgc_code' => $code,
                    'name' => $name,
                    'geographic_level' => $level,
                    'parent_psgc_code' => $parentCode,
                    'city_class' => $cityClass,
                ];
            }
        } finally {
            fclose($handle);
        }
    }
}
