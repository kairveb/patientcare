<?php

namespace App\Services\TriageAi;

use App\Models\Department;
use App\Models\Specialty;

class SpecialtyResolver
{
    public function resolve(string $recommendation): array
    {
        $specialties = Specialty::query()->with('department')->get();
        $departments = Department::query()->where('active', true)->get();

        $specialty = $specialties->first(
            fn (Specialty $item) => mb_strtolower(trim($item->name)) === mb_strtolower(trim($recommendation))
        );
        if ($specialty) {
            $department = $specialty->department
                ?? $departments->first(fn (Department $item) => mb_strtolower($item->name) === mb_strtolower($specialty->name))
                ?? $this->closestDepartment($specialty->name, $departments);

            return $this->resolved($specialty, $department);
        }

        $department = $departments->first(
            fn (Department $item) => mb_strtolower(trim($item->name)) === mb_strtolower(trim($recommendation))
        );
        if (! $department) {
            $closestSpecialty = $this->closestSpecialty($recommendation, $specialties);
            if ($closestSpecialty && $this->similarity($recommendation, $closestSpecialty->name) >= 45) {
                $specialty = $closestSpecialty;
                $department = $specialty->department
                    ?? $departments->first(fn (Department $item) => mb_strtolower($item->name) === mb_strtolower($specialty->name))
                    ?? $this->closestDepartment($specialty->name, $departments);

                return $this->resolved($specialty, $department);
            }

            $department = $this->closestDepartment($recommendation, $departments);
        }

        $specialty = $specialties->first(fn (Specialty $item) => $item->department_id === $department?->id)
            ?? $specialties->first(fn (Specialty $item) => mb_strtolower($item->name) === 'internal medicine')
            ?? $this->closestSpecialty($department?->name ?? $recommendation, $specialties);

        return $this->resolved($specialty, $department);
    }

    private function resolved(?Specialty $specialty, ?Department $department): array
    {
        if (! $department && ! $specialty) {
            return [
                'specialty_id' => null,
                'specialty' => 'General Medicine',
                'department_id' => null,
                'department' => 'General Medicine',
            ];
        }

        if (! $department) {
            return [
                'specialty_id' => $specialty?->id,
                'specialty' => $specialty?->name ?? 'General Medicine',
                'department_id' => null,
                'department' => 'General Medicine',
            ];
        }

        return [
            'specialty_id' => $specialty?->id,
            'specialty' => $specialty?->name ?? $department->name,
            'department_id' => $department->id,
            'department' => $department->name,
        ];
    }

    private function closestDepartment(string $name, $departments): ?Department
    {
        return $departments->sortByDesc(fn (Department $item) => $this->similarity($name, $item->name))->first();
    }

    private function closestSpecialty(string $name, $specialties): ?Specialty
    {
        return $specialties->sortByDesc(fn (Specialty $item) => $this->similarity($name, $item->name))->first();
    }

    private function similarity(string $left, string $right): float
    {
        similar_text(mb_strtolower(trim($left)), mb_strtolower(trim($right)), $percent);

        return $percent;
    }
}
