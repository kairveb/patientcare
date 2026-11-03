<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class HimsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ReferenceDataSeeder::class,
            RbacReconciliationSeeder::class,
            AdminRoleSeeder::class,
            CareRequestRbacSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
