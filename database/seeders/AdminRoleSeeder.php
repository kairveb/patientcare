<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class AdminRoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin']);

        $permissionNames = [
            'manage-users',
            'manage-doctors',
            'view-patients',
            'view-appointments',
            'create-appointments',
            'update-appointments',
            'cancel-appointments',
            'manage-provider-schedules',
            'manage-wards',
            'manage-beds',
            'view-reports',
        ];

        foreach ($permissionNames as $name) {
            Permission::firstOrCreate(['name' => $name], [
                'label' => ucwords(str_replace('-', ' ', $name)),
            ]);
        }

        Role::query()->where('name', 'admin')->firstOrFail()
            ->permissions()->syncWithoutDetaching(
                Permission::query()->whereIn('name', $permissionNames)->pluck('id')
            );
    }
}
