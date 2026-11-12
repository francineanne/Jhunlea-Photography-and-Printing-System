<?php

namespace Tests\Feature\Security;

use App\Enums\AuditAction;
use App\Services\Audit\AuditLogger;
use App\Support\Preflight\PreflightProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/** Phase 9A: php artisan jhunlea:preflight, under faked settings. It must never print a secret. */
class PreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    private const APP_KEY = 'base64:c2VjcmV0LWFwcC1rZXktZm9yLXRoZS1wcmVmbGlnaHQ=';

    private const CLAUDE_KEY = 'sk-ant-preflight-claude-value-0000';

    private const GEMINI_KEY = 'gemini-preflight-value-1111';

    /** @var array<string, bool> */
    private array $missingExtensions = [];

    private bool $connects = true;

    private string $driver = 'mysql';

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->app->instance(PreflightProbe::class, new class($test) extends PreflightProbe
        {
            public function __construct(private readonly PreflightCommandTest $test) {}

            public function extensionLoaded(string $name): bool
            {
                return ! $this->test->isMissing($name);
            }

            public function writable(string $path): bool
            {
                return true;
            }

            public function storageLinked(): bool
            {
                return true;
            }

            public function fileExists(string $path): bool
            {
                return true;
            }

            public function ini(string $key): string|false
            {
                return in_array($key, ['curl.cainfo', 'openssl.cafile'], true) ? 'C:\\certs\\cacert.pem' : parent::ini($key);
            }

            public function databaseDriver(): string
            {
                return $this->test->driverName();
            }

            public function connect(): void
            {
                if (! $this->test->connects()) {
                    throw new PDOException('SQLSTATE[HY000] [1045] Access denied for user secret-db-user at secret-db-host');
                }
            }
        });
    }

    protected function tearDown(): void
    {
        // bootstrap/app.php's own list, as every other test expects it
        TrustProxies::at(['127.0.0.1', '::1']);

        parent::tearDown();
    }

    public function isMissing(string $name): bool
    {
        return $this->missingExtensions[$name] ?? false;
    }

    public function connects(): bool
    {
        return $this->connects;
    }

    public function driverName(): string
    {
        return $this->driver;
    }

    private function production(): void
    {
        config([
            'app.env' => 'production', 'app.debug' => false, 'app.key' => self::APP_KEY, 'app.url' => 'https://jhunlea.example',
            'session.secure' => true, 'session.http_only' => true,
            'assistant.enabled' => true, 'assistant.provider' => 'anthropic', 'assistant.fallback_provider' => 'gemini',
            'assistant.anthropic.api_key' => self::CLAUDE_KEY, 'assistant.api_key' => self::GEMINI_KEY,
        ]);
        TrustProxies::at(['10.0.0.5']);
    }

    /** @return array{0: int, 1: string} */
    private function preflight(): array
    {
        $code = Artisan::call('jhunlea:preflight');
        $output = Artisan::output();

        foreach ([self::APP_KEY, substr(self::APP_KEY, 7), self::CLAUDE_KEY, self::GEMINI_KEY, 'secret-db-user', 'secret-db-host'] as $secret) {
            $this->assertStringNotContainsString($secret, $output, 'The preflight printed a secret');
        }

        return [$code, $output];
    }

    public function test_a_ready_installation_passes_with_only_warnings(): void
    {
        $this->production();
        [$code, $output] = $this->preflight();

        $this->assertSame(0, $code, $output);
        $this->assertStringNotContainsString('FAIL', $output);
        foreach (['PASS  APP_ENV', 'PASS  APP_DEBUG', 'PASS  APP_KEY - set', 'PASS  APP_URL', 'PASS  Session cookie - secure', 'PASS  Trusted proxies',
            'PASS  Database driver - mysql', 'PASS  Database connection', 'PASS  Audit trail - chain intact', 'PASS  Lumi key (anthropic) - set',
            'PASS  Lumi backup key (gemini) - set', 'PASS  CA bundle', 'PASS  PHP extensions', 'PASS  public/storage link', 'WARN  Scheduler'] as $line) {
            $this->assertStringContainsString($line, $output);
        }
        $this->assertMatchesRegularExpression('/Summary: \d+ passed, \d+ warnings, 0 failed\./', $output);
    }

    public function test_a_development_setup_fails_with_a_reason_for_each_problem(): void
    {
        config([
            'app.env' => 'local', 'app.debug' => true, 'app.key' => '', 'app.url' => 'http://localhost:8000', 'session.secure' => false,
            'assistant.enabled' => true, 'assistant.provider' => 'anthropic', 'assistant.fallback_provider' => 'gemini',
            'assistant.anthropic.api_key' => null, 'assistant.api_key' => null,
        ]);
        TrustProxies::at('*');
        $this->driver = 'sqlite';

        [$code, $output] = $this->preflight();

        $this->assertSame(1, $code);
        foreach (['FAIL  APP_ENV - is "local"', 'FAIL  APP_DEBUG', 'FAIL  APP_KEY - missing', 'FAIL  APP_URL', 'FAIL  Session cookie - not secure',
            'FAIL  Trusted proxies - every address', 'FAIL  Database driver - is sqlite', 'FAIL  Lumi key (anthropic) - missing', 'WARN  Lumi backup key (gemini) - missing'] as $line) {
            $this->assertStringContainsString($line, $output);
        }
    }

    public function test_a_database_error_says_only_its_kind(): void
    {
        $this->production();
        $this->connects = false;

        [$code, $output] = $this->preflight();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  Database connection - cannot connect (PDOException', $output);
        $this->assertStringContainsString('FAIL  Audit trail - not checked', $output);
        $this->assertStringNotContainsString('Access denied', $output);
    }

    public function test_missing_extensions_and_a_switched_off_assistant(): void
    {
        $this->production();
        config(['assistant.enabled' => false, 'assistant.anthropic.api_key' => null, 'assistant.api_key' => null]);
        $this->missingExtensions = ['fileinfo' => true, 'gd' => true, 'imagick' => true];

        [$code, $output] = $this->preflight();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  PHP extensions - missing: fileinfo, gd or imagick', $output);
        $this->assertStringContainsString('PASS  Lumi assistant - switched off', $output);
        $this->assertStringNotContainsString('Lumi key', $output);
    }

    public function test_a_fake_provider_in_production_fails(): void
    {
        $this->production();
        config(['assistant.provider' => 'fake', 'assistant.fallback_provider' => null]);

        [$code, $output] = $this->preflight();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  Lumi provider - "fake" is not a real provider', $output);
    }

    public function test_a_broken_audit_chain_fails(): void
    {
        $this->production();
        app(AuditLogger::class)->record(AuditAction::LoggedIn);
        DB::table('audit_logs')->update(['summary' => 'Edited outside the app.']);

        [$code, $output] = $this->preflight();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  Audit trail - broken', $output);
    }
}
