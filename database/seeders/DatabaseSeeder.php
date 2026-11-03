<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            ReferenceDataSeeder::class,
            RbacReconciliationSeeder::class,
            AdminRoleSeeder::class,
            CareRequestRbacSeeder::class,
        ]);

        if (! app()->environment('production')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
