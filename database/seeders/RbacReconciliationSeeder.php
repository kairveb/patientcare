<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RbacReconciliationSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'assign-beds',
            'execute-discharges',
            'manage-provider-schedules',
            'manage-opd-queue',
            'order-discharges',
            'update-bed-status',
            'view-payment-status',
            'view-opd-queue',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name], [
                'label' => ucwords(str_replace('-', ' ', $name)),
            ]);
        }

        $newPermissionIds = Permission::whereIn('name', $permissions)->pluck('id');
        Role::where('name', 'super-admin')->firstOrFail()
            ->permissions()->syncWithoutDetaching($newPermissionIds);

        $manageProviderSchedulesId = Permission::where('name', 'manage-provider-schedules')->value('id');
        Role::whereIn('name', ['registration', 'doctor', 'nurse', 'patient'])->get()
            ->each(fn (Role $role) => $role->permissions()->detach($manageProviderSchedulesId));

        $this->reconcileRole('registration', [
            'view-beds', 'view-admissions', 'assign-beds', 'view-reports',
            'view-opd-queue', 'manage-opd-queue',
        ]);

        $this->reconcileRole('doctor', [
            'order-discharges', 'view-opd-queue', 'manage-opd-queue', 'view-payment-status', 'view-reports',
        ], [
            'update-patients', 'update-appointments', 'discharge-patients', 'view-billing',
        ]);

        $this->reconcileRole('nurse', [
            'execute-discharges', 'update-bed-status', 'view-payment-status', 'view-opd-queue', 'view-reports',
        ], [
            'discharge-patients', 'create-admissions', 'manage-admissions',
            'manage-wards', 'manage-beds', 'update-patients', 'view-billing', 'manage-opd-queue',
        ]);

        $this->reconcileRole('patient', [
            'view-appointments', 'create-appointments', 'update-appointments', 'cancel-appointments',
        ], [
            'view-opd-queue', 'manage-opd-queue', 'view-billing', 'view-reports',
        ]);
    }

    private function reconcileRole(string $roleName, array $grant, array $revoke = []): void
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $role->permissions()->syncWithoutDetaching(
            Permission::whereIn('name', $grant)->pluck('id')
        );

        if ($revoke !== []) {
            $role->permissions()->detach(
                Permission::whereIn('name', $revoke)->pluck('id')
            );
        }
    }
}
