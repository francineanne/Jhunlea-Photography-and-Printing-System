<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * No users are ever created here: the owner account is made with `php artisan jhunlea:create-owner`.
     */
    public function run(): void
    {
        // Placeholder catalog for development only, never on a real (production) database
        if (app()->environment('local')) {
            $this->call([SampleCatalogSeeder::class, SamplePackagesSeeder::class, BookingScheduleSeeder::class]);
        }
    }
}
