<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class IntegrationRbacSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'view-integration-logs',
            'manage-integrations',
            'retry-integration-delivery',
            'request-patient-transport',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name], [
                'label' => ucwords(str_replace('-', ' ', $name)),
            ]);
        }

        $ids = Permission::query()->whereIn('name', $permissions)->pluck('id', 'name');
        Role::query()->whereIn('name', ['admin', 'super-admin'])->get()->each(
            fn (Role $role) => $role->permissions()->syncWithoutDetaching([$ids['view-integration-logs']])
        );
        Role::query()->where('name', 'super-admin')->firstOrFail()
            ->permissions()->syncWithoutDetaching([
                $ids['manage-integrations'],
                $ids['retry-integration-delivery'],
                $ids['request-patient-transport'],
            ]);
        Role::query()->whereIn('name', ['nurse', 'registration'])->get()->each(
            fn (Role $role) => $role->permissions()->syncWithoutDetaching([$ids['request-patient-transport']])
        );
    }
}
