<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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
}
