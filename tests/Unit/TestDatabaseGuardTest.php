<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabaseGuard;

/**
 * The guard's decision, with made-up settings only: no application is started and no database is reached.
 * The files checked below are read as text.
 */
class TestDatabaseGuardTest extends TestCase
{
    private const SAFE = ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'url' => null]];

    public function test_testing_on_in_memory_sqlite_is_allowed(): void
    {
        $this->assertNull(TestDatabaseGuard::problem('testing', 'sqlite', self::SAFE));
        $this->assertNull(TestDatabaseGuard::problem('testing', 'sqlite', ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'url' => '']]));
    }

    public function test_the_incident_would_have_been_stopped(): void
    {
        // What the developer's terminal produced: the .env values exported, phpunit.xml unable to override them
        $connections = self::SAFE + ['mysql' => ['driver' => 'mysql', 'database' => 'jhunlea', 'url' => null, 'host' => '127.0.0.1']];

        $problem = TestDatabaseGuard::problem('local', 'mysql', $connections);

        $this->assertNotNull($problem);
        $this->assertStringStartsWith('Refusing to run tests: this would touch the database mysql "jhunlea"', $problem);
        $this->assertStringContainsString('APP_ENV=local', $problem);
        $this->assertStringContainsString('Open a new terminal without .env variables set.', $problem);
    }

    /** @return array<string, array{string, ?string, array<string, mixed>}> */
    public static function unsafe(): array
    {
        $mysql = ['mysql' => ['driver' => 'mysql', 'database' => 'jhunlea', 'url' => null]];

        return [
            'local environment, even on sqlite memory' => ['local', 'sqlite', self::SAFE],
            'production environment' => ['production', 'sqlite', self::SAFE],
            'testing environment on mysql' => ['testing', 'mysql', self::SAFE + $mysql],
            'sqlite file instead of memory' => ['testing', 'sqlite', ['sqlite' => ['driver' => 'sqlite', 'database' => 'C:/xampp/htdocs/jhunlea/database/database.sqlite', 'url' => null]]],
            'empty sqlite database name' => ['testing', 'sqlite', ['sqlite' => ['driver' => 'sqlite', 'database' => '', 'url' => null]]],
            'a database url on the sqlite connection' => ['testing', 'sqlite', ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'url' => 'mysql://root@127.0.0.1/jhunlea']]],
            'a connection named sqlite that is mysql' => ['testing', 'sqlite', ['sqlite' => ['driver' => 'mysql', 'database' => ':memory:', 'url' => null]]],
            'an unknown default connection' => ['testing', 'nothing', self::SAFE],
            'no default connection' => ['testing', null, self::SAFE],
        ];
    }

    #[DataProvider('unsafe')]
    public function test_anything_else_is_refused(string $environment, ?string $default, array $connections): void
    {
        $this->assertStringStartsWith('Refusing to run tests:', (string) TestDatabaseGuard::problem($environment, $default, $connections));
    }

    public function test_phpunit_xml_forces_every_test_value_on_every_channel(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2).'/phpunit.xml');
        $env = [];
        $server = [];

        foreach ($xml->php->env as $item) {
            $env[(string) $item['name']] = [(string) $item['value'], (string) $item['force']];
        }
        foreach ($xml->php->server as $item) {
            $server[(string) $item['name']] = (string) $item['value'];
        }

        $expected = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'DB_HOST' => '', 'DB_PORT' => '', 'DB_USERNAME' => '', 'DB_PASSWORD' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'FILESYSTEM_DISK' => 'local', 'PRINTING_FILES_DISK' => 'local', 'BOOKING_IMAGES_DISK' => 'local',
        ];

        foreach ($expected as $name => $value) {
            $this->assertSame([$value, 'true'], $env[$name] ?? null, "<env name=\"{$name}\"> must be \"{$value}\" with force=\"true\"");
            // Laravel reads $_SERVER first, and force="true" does not reach $_SERVER: the <server> copy is required
            $this->assertSame($value, $server[$name] ?? null, "<server name=\"{$name}\"> must be \"{$value}\"");
        }

        foreach ($env as $name => [$value, $force]) {
            $this->assertSame('true', $force, "<env name=\"{$name}\"> must have force=\"true\"");
            $this->assertArrayHasKey($name, $server, "<env name=\"{$name}\"> needs a <server> copy");
        }
    }

    public function test_the_mysql_mode_accepts_only_a_local_database_ending_with_test(): void
    {
        $mysql = fn (string $database, string $host = '127.0.0.1', ?string $url = null, string $driver = 'mysql') => ['mysql' => ['driver' => $driver, 'database' => $database, 'host' => $host, 'url' => $url]];

        $this->assertNull(TestDatabaseGuard::mysqlProblem('testing', 'mysql', $mysql('jhunlea_test')));
        $this->assertNull(TestDatabaseGuard::mysqlProblem('testing', 'mysql', $mysql('jhunlea_test', 'localhost', null, 'mariadb')));

        foreach ([
            'the real database' => ['testing', 'mysql', $mysql('jhunlea')],
            'a name that only contains test' => ['testing', 'mysql', $mysql('jhunlea_test_copy')],
            'another machine' => ['testing', 'mysql', $mysql('jhunlea_test', 'db.example.com')],
            'a database url' => ['testing', 'mysql', $mysql('jhunlea_test', '127.0.0.1', 'mysql://root@127.0.0.1/jhunlea')],
            'local environment' => ['local', 'mysql', $mysql('jhunlea_test')],
            'sqlite connection' => ['testing', 'sqlite', self::SAFE],
        ] as $case => [$environment, $default, $connections]) {
            $this->assertStringStartsWith('Refusing to run the MySQL tests:', (string) TestDatabaseGuard::mysqlProblem($environment, $default, $connections), $case);
        }
    }

    public function test_the_normal_config_forces_the_mysql_marker_off_and_the_mysql_config_forces_the_test_database(): void
    {
        $root = dirname(__DIR__, 2);
        $normal = simplexml_load_file($root.'/phpunit.xml');
        $this->assertCount(1, $normal->xpath('//env[@name="'.TestDatabaseGuard::MYSQL_MARKER.'"][@value=""][@force="true"]'));
        $this->assertCount(1, $normal->xpath('//server[@name="'.TestDatabaseGuard::MYSQL_MARKER.'"][@value=""]'));

        $mysql = simplexml_load_file($root.'/phpunit.mysql.xml');
        foreach (['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_DATABASE' => 'jhunlea_test', 'APP_ENV' => 'testing', TestDatabaseGuard::MYSQL_MARKER => '1', 'DB_URL' => ''] as $name => $value) {
            $this->assertCount(1, $mysql->xpath("//env[@name=\"{$name}\"][@value=\"{$value}\"][@force=\"true\"]"), "phpunit.mysql.xml must force {$name}={$value}");
            $this->assertCount(1, $mysql->xpath("//server[@name=\"{$name}\"][@value=\"{$value}\"]"), "phpunit.mysql.xml needs a <server> copy of {$name}");
        }
    }

    public function test_every_feature_test_goes_through_the_guarded_test_case(): void
    {
        $root = dirname(__DIR__, 2).'/tests';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/Feature'));

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            if (preg_match('/^(abstract\s+)?(final\s+)?class\s/m', $code) !== 1) {
                continue; // traits and helpers
            }

            $this->assertMatchesRegularExpression('/^use Tests\\\\TestCase;$/m', $code, $file->getFilename().' must extend Tests\TestCase (the guarded one)');
            $this->assertStringNotContainsString('function createApplication', $code, $file->getFilename().' must not replace createApplication()');
        }

        $this->assertStringContainsString('TestDatabaseGuard::check($app);', (string) file_get_contents($root.'/TestCase.php'));
    }
}
