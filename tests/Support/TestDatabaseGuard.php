<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * Stops the test run before anything can reach a real database.
 *
 * Laravel's TestCase creates the application (createApplication) BEFORE it runs the test traits, so RefreshDatabase,
 * which wipes and rebuilds every table, has not run yet when check() looks at the settings the application really
 * resolved. If they are not "testing" + SQLite + ":memory:", the whole process stops at once with a clear message:
 * not one table, file, cache entry or session is touched. This is the second layer; phpunit.xml forces the safe values
 * first. It happened once: a terminal had the .env values exported (APP_ENV=local, DB_CONNECTION=mysql ...) and the
 * tests ran against the developer's real MySQL database.
 */
final class TestDatabaseGuard
{
    /**
     * Why the tests must not run with these settings, or null when they are safe. Kept free of Laravel so it can be
     * tested with made-up values without starting anything.
     *
     * @param  array<string, mixed>  $connections  config('database.connections')
     */
    public static function problem(string $environment, ?string $default, array $connections): ?string
    {
        $connection = $connections[$default] ?? [];
        $driver = $connection['driver'] ?? '(unknown driver)';
        $database = (string) ($connection['database'] ?? '');
        $url = (string) ($connection['url'] ?? '');

        $safe = $environment === 'testing'
            && $default === 'sqlite'
            && $driver === 'sqlite'
            && $database === ':memory:'
            && $url === '';

        if ($safe) {
            return null;
        }

        $target = $url !== '' ? "{$driver} at {$url}" : "{$driver} \"{$database}\" (connection \"{$default}\")";

        return "Refusing to run tests: this would touch the database {$target}, with APP_ENV={$environment}. "
            .'Open a new terminal without .env variables set. (Tests only ever run on an in-memory SQLite database; '
            .'see "Running the tests safely" in AGENTS.md.)';
    }

    /** The marker phpunit.mysql.xml forces; phpunit.xml forces it empty, so a normal run is always SQLite only. */
    public const MYSQL_MARKER = 'JHUNLEA_MYSQL_TESTS';

    /**
     * The one other allowed shape (Phase 9B, phpunit.mysql.xml): testing + MySQL on this machine + a database whose
     * name ends with "_test". Kept free of Laravel like problem().
     *
     * @param  array<string, mixed>  $connections  config('database.connections')
     */
    public static function mysqlProblem(string $environment, ?string $default, array $connections): ?string
    {
        $connection = $connections[$default] ?? [];
        $driver = $connection['driver'] ?? '(unknown driver)';
        $database = (string) ($connection['database'] ?? '');
        $url = (string) ($connection['url'] ?? '');
        $host = strtolower((string) ($connection['host'] ?? ''));

        $safe = $environment === 'testing'
            && in_array($default, ['mysql', 'mariadb'], true)
            && in_array($driver, ['mysql', 'mariadb'], true)
            && str_ends_with($database, '_test')
            && $url === ''
            && in_array($host, ['127.0.0.1', 'localhost', '::1'], true);

        return $safe ? null : "Refusing to run the MySQL tests: they only run on a local database whose name ends with \"_test\" (got {$driver} \"{$database}\" on \"{$host}\", APP_ENV={$environment}). "
            .'Use phpunit.mysql.xml; see docs/MYSQL_TESTING.md.';
    }

    /** The name of the database the live connection really uses (not the config text). */
    public static function liveDatabaseName(Application $app): string
    {
        return (string) ($app->make('db')->connection()->selectOne('select database() as name')->name ?? '');
    }

    public static function mysqlMode(): bool
    {
        return getenv(self::MYSQL_MARKER) === '1';
    }

    /** Stops the whole run when the application's settings are not safe. Nothing is touched before or after. */
    public static function check(Application $app): void
    {
        $config = $app->make('config');

        if (self::mysqlMode()) {
            $problem = self::mysqlProblem((string) $app->environment(), $config->get('database.default'), (array) $config->get('database.connections', []));

            // The settings look right: ask the server itself which database this connection opened
            if ($problem === null) {
                try {
                    $live = self::liveDatabaseName($app);
                    $problem = str_ends_with($live, '_test') ? null : "Refusing to run the MySQL tests: the live connection opened \"{$live}\", not a database ending with \"_test\".";
                } catch (\Throwable $e) {
                    $problem = 'Refusing to run the MySQL tests: cannot connect to the test database ('.class_basename($e).'). Is XAMPP MySQL running and does jhunlea_test exist? See docs/MYSQL_TESTING.md.';
                }
            }
        } else {
            $problem = self::problem((string) $app->environment(), $config->get('database.default'), (array) $config->get('database.connections', []));
        }

        if ($problem === null) {
            return;
        }

        fwrite(STDERR, PHP_EOL.str_repeat('!', 72).PHP_EOL.$problem.PHP_EOL.str_repeat('!', 72).PHP_EOL);

        // exit, not an exception: an exception fails one test and PHPUnit moves on to the next; exit ends the run here
        exit(1);
    }
}
