<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Assistant\Eval\OwnerFixtures;
use Database\Seeders\BookingScheduleSeeder;
use Database\Seeders\SampleCatalogSeeder;
use Database\Seeders\SamplePackagesSeeder;
use Illuminate\Support\Carbon;

/**
 * The owner's Ask Lumi tests (Phase 7E-3a): the sample catalog and the eval's owner data (OwnerFixtures) in the test
 * database, with the clock frozen on Thursday 2026-11-05 10:00 Manila. The tests compare every tool with the existing
 * admin services, so they never depend on the eval's expected numbers.
 */
trait SeedsOwnerShop
{
    protected User $owner;

    protected function seedOwnerShop(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00', 'Asia/Manila')->utc());
        app(SampleCatalogSeeder::class)->run();
        app(SamplePackagesSeeder::class)->run();
        app(BookingScheduleSeeder::class)->run();

        $this->owner = User::factory()->owner()->create(['name' => 'Test Owner']);
        (new OwnerFixtures)->build($this->owner, ['ben' => User::factory()->create(['name' => 'Ben Test'])]);
    }
}
