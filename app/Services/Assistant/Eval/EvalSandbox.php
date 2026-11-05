<?php

namespace App\Services\Assistant\Eval;

use App\Services\ShopSettingsService;
use Carbon\CarbonImmutable;
use Database\Seeders\BookingScheduleSeeder;
use Database\Seeders\SampleCatalogSeeder;
use Database\Seeders\SamplePackagesSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The eval's own throwaway database (Phase 7E-2b). It is ONE SQLite file under storage/app/evals (git-ignored): open()
 * deletes and recreates that file, points a connection of its own at it, makes that connection the default for this
 * process only, ASSERTS that the active connection really is that file (and aborts otherwise) before anything is
 * written, runs the project's migrations on it, seeds the sample catalog, the hours and fake business info, freezes the
 * clock and raises the assistant limits in this process. close() puts the old default connection back and deletes the
 * file. It never opens, reads or writes the MySQL connection.
 */
class EvalSandbox
{
    public const CONNECTION = 'assistant_eval';

    /** Thursday, Nov 5, 2026, 10:00 AM in Manila: the shop is open (Monday to Saturday 9 to 5 in the seeded hours). */
    public const FROZEN_NOW = '2026-11-05 10:00';

    /** The fake business info the contact cases expect. */
    public const BUSINESS = [
        'shop_name' => 'Jhunlea Photography & Printing',
        'address' => 'Eval Sample Address, Sample City',
        'phone' => '0917 000 1234',
        'email' => 'eval-shop@example.test',
    ];

    private ?string $previousDefault = null;

    private ?string $previousCache = null;

    private bool $open = false;

    public function __construct(private readonly string $path)
    {
        $root = str_replace('\\', '/', storage_path('app/evals')).'/';
        $normal = str_replace('\\', '/', $path);

        if (! str_starts_with($normal, $root) || ! str_ends_with($normal, '.sqlite') || str_contains($normal, '..')) {
            throw new RuntimeException('The eval database must be a .sqlite file inside storage/app/evals.');
        }
    }

    public static function defaultPath(): string
    {
        return storage_path('app/evals/eval.sqlite');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function open(): void
    {
        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0775, true);
        }

        // Only our own file is ever deleted or recreated
        if (is_file($this->path)) {
            unlink($this->path);
        }

        touch($this->path);

        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'sqlite',
            'database' => $this->path,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->previousDefault = (string) config('database.default');
        DB::purge(self::CONNECTION);
        config(['database.default' => self::CONNECTION]);
        DB::setDefaultConnection(self::CONNECTION);
        $this->open = true;

        $this->assertActive();

        Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]);
        $this->assertActive();

        // Limits never distort a run: they are raised in this process only, and the cache is in memory
        $this->previousCache = (string) config('cache.default');
        config([
            'cache.default' => 'array',
            'assistant.limits.per_minute_per_user' => 1_000_000,
            'assistant.limits.per_day_per_user' => 1_000_000,
            'assistant.limits.per_day_per_guest_ip' => 1_000_000,
            'assistant.limits.global_daily_budget' => 1_000_000,
        ]);
        app('cache')->setDefaultDriver('array');
        app()->forgetInstance(RateLimiter::class);

        $this->freeze(self::FROZEN_NOW);

        app(SampleCatalogSeeder::class)->run();
        app(SamplePackagesSeeder::class)->run();
        app(BookingScheduleSeeder::class)->run();
        app(ShopSettingsService::class)->saveBusiness(self::BUSINESS);
    }

    /**
     * Aborts unless the default connection is the eval's own SQLite file. Called before every write phase.
     *
     * @throws RuntimeException
     */
    public function assertActive(): void
    {
        $connection = DB::connection();
        $name = str_replace('\\', '/', (string) $connection->getDatabaseName());

        if (! $this->open || $connection->getName() !== self::CONNECTION || $connection->getDriverName() !== 'sqlite'
            || $name !== str_replace('\\', '/', $this->path)) {
            throw new RuntimeException('Refusing to run the eval: the active database is not the eval\'s own SQLite file.');
        }
    }

    /** Sets the clock to a Manila wall time ("YYYY-MM-DD HH:MM"). */
    public function freeze(string $manila): void
    {
        $at = Carbon::parse($manila, 'Asia/Manila')->utc();
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at->toImmutable());
    }

    public function close(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        if ($this->previousCache !== null) {
            config(['cache.default' => $this->previousCache]);
            app('cache')->setDefaultDriver($this->previousCache);
        }

        if ($this->previousDefault !== null) {
            config(['database.default' => $this->previousDefault]);
            DB::setDefaultConnection($this->previousDefault);
        }

        DB::purge(self::CONNECTION);
        $this->open = false;

        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
