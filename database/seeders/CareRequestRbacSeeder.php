<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class CareRequestRbacSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'create-care-requests',
            'approve-care-requests',
            'view-care-requests',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name], [
                'label' => ucwords(str_replace('-', ' ', $name)),
            ]);
        }

        $permissionIds = Permission::query()->whereIn('name', $permissions)->pluck('id', 'name');
        $grants = [
            'doctor' => ['create-care-requests', 'view-care-requests'],
            'registration' => ['approve-care-requests', 'view-care-requests'],
            'nurse' => ['view-care-requests'],
            'patient' => ['view-care-requests'],
            'super-admin' => $permissions,
        ];

        foreach ($grants as $roleName => $names) {
            Role::query()->where('name', $roleName)->firstOrFail()
                ->permissions()->syncWithoutDetaching(
                    collect($names)->map(fn (string $name) => $permissionIds[$name])->all()
                );
        }
    }
}
