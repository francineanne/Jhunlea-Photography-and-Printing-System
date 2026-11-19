<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Phase 10B: bookings whose downpayment was not paid by the deadline are cancelled. On hosting, the server's cron runs
// `php artisan schedule:run` every minute; without cron the same work runs lazily when booking pages are opened
Schedule::command('bookings:expire-unpaid')->everyFifteenMinutes()->withoutOverlapping();
// Phase 10K: the notices about automatic events (ran-out holds, due-soon deadlines, unclaimable orders, tomorrow's shoots)
Schedule::command('notifications:system-events')->everyFifteenMinutes()->withoutOverlapping();
use Tests\MySql\Support\RaceWorkerCommand;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 9B, tests only: the worker of the MySQL race tests. It lives in tests/ (the dev autoloader only, so a
// production install without dev packages never has it) and exists only in the testing environment
if (app()->environment('testing') && class_exists(RaceWorkerCommand::class)) {
    Artisan::registerCommand(app(RaceWorkerCommand::class));
}
