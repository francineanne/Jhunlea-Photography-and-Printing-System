<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Tests\MySql\Support\RaceWorkerCommand;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 9B, tests only: the worker of the MySQL race tests. It lives in tests/ (the dev autoloader only, so a
// production install without dev packages never has it) and exists only in the testing environment
if (app()->environment('testing') && class_exists(RaceWorkerCommand::class)) {
    Artisan::registerCommand(app(RaceWorkerCommand::class));
}
