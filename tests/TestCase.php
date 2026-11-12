<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every feature test starts here. The application is created before RefreshDatabase or any other trait runs, so
     * this is where TestDatabaseGuard stops the run if the settings could reach a real database. Do not override
     * createApplication() in a test.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        TestDatabaseGuard::check($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Views use @vite; tests must not depend on a built manifest
        $this->withoutVite();
    }

    /**
     * Call at the start of a test that drops a table or column, or truncates one (Phase 9B). SQLite rolls such DDL back
     * with the test's transaction; MySQL commits it at once, so after this test the next one starts from a fresh
     * migration of the (guarded, _test) database instead of a broken schema.
     */
    protected function changesSchema(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
        }
    }
}
