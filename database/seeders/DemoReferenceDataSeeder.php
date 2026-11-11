<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DemoReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoProvidersAndSlotsSeeder::class,
            DemoWardsRoomsBedsSeeder::class,
            DemoChargeRatesSeeder::class,
        ]);
    }
}
