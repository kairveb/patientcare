<?php

namespace Database\Seeders;

use App\Models\AppointmentType;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Specialty;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRolesAndPermissions();
        $this->seedDepartmentsAndSpecialties();
        $this->seedAppointmentTypes();
    }

    private function seedRolesAndPermissions(): void
    {
        $roles = [
            'super-admin' => 'Super Admin',
            'registration' => 'Registration / Front Desk',
            'doctor' => 'Doctor',
            'nurse' => 'Nurse',
            'patient' => 'Patient',
        ];

        foreach ($roles as $name => $label) {
            Role::firstOrCreate(['name' => $name], ['label' => $label]);
        }

        $permissions = [
            'manage-users', 'manage-roles',
            'view-patients', 'create-patients', 'update-patients', 'delete-patients', 'verify-patients',
            'view-appointments', 'create-appointments', 'update-appointments', 'cancel-appointments', 'delete-appointments', 'view-own-appointments',
            'view-encounters', 'create-encounters', 'update-encounters',
            'view-triage', 'create-triage', 'update-triage',
            'view-er', 'create-er-visits', 'triage-patients',
            'view-beds', 'manage-beds', 'view-wards', 'manage-wards',
            'assign-beds', 'update-bed-status',
            'view-admissions', 'manage-admissions', 'create-admissions', 'transfer-patients', 'discharge-patients',
            'execute-discharges', 'order-discharges', 'view-payment-status',
            'view-reports', 'view-audit-logs',
            'view-telehealth', 'start-telehealth', 'join-telehealth', 'view-own-telehealth',
            'view-own-medical-history', 'portal-dashboard',
            'view-billing', 'manage-billing',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name], ['label' => ucwords(str_replace('-', ' ', $name))]);
        }

        $rolePermissions = [
            'super-admin' => $permissions,
            'registration' => [
                'view-patients', 'create-patients', 'update-patients', 'verify-patients',
                'view-appointments', 'create-appointments', 'update-appointments', 'cancel-appointments',
                'view-er', 'create-er-visits', 'view-own-appointments',
                'view-beds', 'view-admissions', 'assign-beds',
            ],
            'doctor' => [
                'view-patients', 'view-appointments', 'update-appointments',
                'view-encounters', 'create-encounters', 'update-encounters',
                'view-triage', 'create-triage', 'update-triage', 'view-er', 'triage-patients',
                'view-beds', 'view-wards', 'view-admissions', 'create-admissions', 'transfer-patients', 'order-discharges',
                'view-telehealth', 'start-telehealth', 'join-telehealth',
                'view-own-medical-history', 'portal-dashboard', 'view-billing', 'view-reports',
            ],
            'nurse' => [
                'view-patients', 'view-appointments', 'view-encounters', 'create-encounters',
                'view-triage', 'create-triage', 'update-triage', 'view-er', 'create-er-visits', 'triage-patients',
                'view-beds', 'view-wards', 'view-admissions', 'transfer-patients', 'update-bed-status',
                'execute-discharges', 'view-payment-status', 'view-telehealth', 'join-telehealth',
                'view-own-medical-history', 'portal-dashboard',
            ],
            'patient' => [
                'view-appointments', 'create-appointments', 'update-appointments', 'cancel-appointments',
                'view-own-appointments', 'view-own-medical-history', 'view-own-telehealth',
                'portal-dashboard', 'view-telehealth', 'join-telehealth',
            ],
        ];

        foreach ($rolePermissions as $role => $permissionNames) {
            Role::where('name', $role)->firstOrFail()
                ->permissions()->syncWithoutDetaching(Permission::whereIn('name', $permissionNames)->pluck('id'));
        }
    }

    private function seedDepartmentsAndSpecialties(): void
    {
        foreach ([
            ['code' => 'CAR', 'name' => 'Cardiology', 'phone' => '1234'],
            ['code' => 'PED', 'name' => 'Pediatrics', 'phone' => '2345'],
            ['code' => 'ORT', 'name' => 'Orthopedics', 'phone' => '3456'],
            ['code' => 'GEN', 'name' => 'General Medicine', 'phone' => '4567'],
            ['code' => 'EMG', 'name' => 'Emergency Medicine', 'phone' => '5678'],
        ] as $department) {
            Department::firstOrCreate(
                ['code' => $department['code']],
                ['name' => $department['name'], 'phone' => $department['phone']]
            );
        }

        foreach (['Cardiology', 'Pediatrics', 'Orthopedics', 'Internal Medicine', 'Emergency Medicine'] as $specialty) {
            Specialty::firstOrCreate(['name' => $specialty]);
        }
    }

    private function seedAppointmentTypes(): void
    {
        AppointmentType::firstOrCreate(['name' => 'Outpatient'], ['default_duration' => 30, 'telehealth' => false]);
        AppointmentType::firstOrCreate(['name' => 'Telehealth'], ['default_duration' => 30, 'telehealth' => true]);
    }
}
