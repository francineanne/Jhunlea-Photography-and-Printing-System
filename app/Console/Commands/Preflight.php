<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditChain;
use App\Support\Preflight\PreflightProbe;
use App\Support\ShopSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Middleware\TrustProxies;
use PDOException;
use ReflectionProperty;
use Throwable;

/**
 * Production-readiness check before hosting (Phase 9A). Read only: it changes no setting, file or row (audit:verify
 * keeps its own cached result, as when it is run by hand). It never prints a secret: keys are only "set" or
 * "missing", a database error only its kind. Exit 1 when any check FAILs.
 */
#[Signature('jhunlea:preflight')]
#[Description('Check that this installation is ready for production (PASS, WARN, FAIL; never prints secrets)')]
class Preflight extends Command
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $results = [];

    public function handle(PreflightProbe $probe, ShopSettings $settings): int
    {
        $this->application();
        $this->sessionAndProxies();
        $this->folders($probe);
        $this->php($probe, $settings);
        $database = $this->database($probe);
        $this->audit($database);
        $this->assistant($probe);
        $this->caches();

        foreach ($this->results as [$status, $label, $detail]) {
            $line = str_pad($status, 5).' '.$label.($detail !== '' ? ' - '.$detail : '');
            match ($status) {
                'PASS' => $this->line($line),
                'WARN' => $this->warn($line),
                default => $this->error($line),
            };
        }

        $count = fn (string $status) => count(array_filter($this->results, fn ($row) => $row[0] === $status));
        $this->newLine();
        $this->line("Summary: {$count('PASS')} passed, {$count('WARN')} warnings, {$count('FAIL')} failed.");

        return $count('FAIL') > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function add(string $status, string $label, string $detail = ''): void
    {
        $this->results[] = [$status, $label, $detail];
    }

    private function check(bool $ok, string $label, string $pass, string $fail, string $failStatus = 'FAIL'): void
    {
        $this->add($ok ? 'PASS' : $failStatus, $label, $ok ? $pass : $fail);
    }

    private function application(): void
    {
        $env = (string) config('app.env');
        $this->check($env === 'production', 'APP_ENV', 'production', "is \"{$env}\"; set APP_ENV=production");
        $this->check(config('app.debug') === false, 'APP_DEBUG', 'false', 'is on; set APP_DEBUG=false so errors never show a stack trace');

        $key = (string) config('app.key');
        $bytes = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $this->check(is_string($bytes) && strlen($bytes) === 32, 'APP_KEY', 'set', 'missing or not a valid key; run php artisan key:generate once');

        $url = (string) config('app.url');
        $this->check(str_starts_with($url, 'https://'), 'APP_URL', 'https', 'is not https; set APP_URL to the https address of the site');
    }

    private function sessionAndProxies(): void
    {
        $this->check(config('session.secure') === true, 'Session cookie', 'secure (https only)', 'not secure; use an https APP_URL or set SESSION_SECURE_COOKIE=true');
        $this->check(config('session.http_only') === true, 'Session cookie', 'http only', 'readable by scripts; set SESSION_HTTP_ONLY=true');

        $proxies = $this->trustedProxies();
        if ($proxies === ['*'] || $proxies === '*') {
            $this->add('FAIL', 'Trusted proxies', 'every address is trusted, so anyone can fake their IP and the rate limits; list the host\'s proxy addresses in bootstrap/app.php');
        } elseif ($proxies === null || $proxies === []) {
            $this->add('WARN', 'Trusted proxies', 'none; if the host puts a proxy or load balancer in front of the app, list its addresses in bootstrap/app.php');
        } elseif (array_diff((array) $proxies, ['127.0.0.1', '::1']) === []) {
            $this->add('WARN', 'Trusted proxies', 'this machine only (127.0.0.1, ::1); if the host ends https at a proxy, add its addresses in bootstrap/app.php');
        } else {
            $this->add('PASS', 'Trusted proxies', count((array) $proxies).' address(es) listed');
        }
    }

    /** @return array<int, string>|string|null */
    private function trustedProxies(): array|string|null
    {
        $property = new ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies');

        return $property->getValue();
    }

    private function folders(PreflightProbe $probe): void
    {
        foreach (['storage' => storage_path(), 'bootstrap/cache' => base_path('bootstrap/cache')] as $label => $path) {
            $this->check($probe->writable($path), "Folder {$label}", 'writable', 'not writable by PHP');
        }
        $this->check($probe->storageLinked(), 'public/storage link', 'present', 'missing; run php artisan storage:link (package and theme photos are served from it)');
    }

    private function php(PreflightProbe $probe, ShopSettings $settings): void
    {
        // The limits the shop asked for (Settings, else the defaults) against PHP's own: ShopSettings caps them quietly
        try {
            $capped = array_values(array_filter(array_keys(ShopSettings::LIMITS), fn (string $field) => $settings->capped($field)));
            $php = 'upload_max_filesize '.$probe->ini('upload_max_filesize').', post_max_size '.$probe->ini('post_max_size').', max_file_uploads '.$probe->ini('max_file_uploads');
            $this->check($capped === [], 'PHP upload limits', $php, 'lower than the shop\'s upload limits ('.implode(', ', $capped)."), so those are capped: {$php}", 'WARN');
        } catch (Throwable $e) {
            $this->add('WARN', 'PHP upload limits', 'could not compare with the shop\'s limits ('.class_basename($e).')');
        }

        $missing = array_values(array_filter(['curl', 'openssl', 'mbstring', 'fileinfo', 'pdo_mysql'], fn (string $name) => ! $probe->extensionLoaded($name)));
        if (! $probe->extensionLoaded('gd') && ! $probe->extensionLoaded('imagick')) {
            $missing[] = 'gd or imagick';
        }
        $this->check($missing === [], 'PHP extensions', 'curl, openssl, mbstring, fileinfo, pdo_mysql, gd/imagick', 'missing: '.implode(', ', $missing));
    }

    private function database(PreflightProbe $probe): bool
    {
        $driver = $probe->databaseDriver();
        $this->check(in_array($driver, ['mysql', 'mariadb'], true), 'Database driver', $driver, "is {$driver}; production uses MySQL (DB_CONNECTION=mysql)");

        try {
            $probe->connect();
            $this->add('PASS', 'Database connection', 'connected');

            return true;
        } catch (Throwable $e) {
            // Only the kind of failure: a driver message can carry the host or the user name
            $code = $e instanceof PDOException && is_string($e->getCode()) ? ' SQLSTATE '.$e->getCode() : '';
            $this->add('FAIL', 'Database connection', 'cannot connect ('.class_basename($e).$code.')');

            return false;
        }
    }

    private function audit(bool $database): void
    {
        if (! $database) {
            $this->add('FAIL', 'Audit trail', 'not checked: no database connection');

            return;
        }

        try {
            $result = app(AuditChain::class)->verify();
            $this->check($result['ok'], 'Audit trail', "chain intact ({$result['checked']} rows)", 'broken: '.$result['message'].' Run php artisan audit:verify');
        } catch (Throwable $e) {
            $this->add('FAIL', 'Audit trail', 'could not be checked ('.class_basename($e).'); were the migrations run?');
        }
    }

    private function assistant(PreflightProbe $probe): void
    {
        if (! config('assistant.enabled')) {
            $this->add('PASS', 'Lumi assistant', 'switched off (AI_ENABLED=false); no keys needed');

            return;
        }

        $keys = ['anthropic' => 'assistant.anthropic.api_key', 'gemini' => 'assistant.api_key'];
        $primary = (string) config('assistant.provider');
        $backup = config('assistant.fallback_provider');

        if (! isset($keys[$primary])) {
            $this->add('FAIL', 'Lumi provider', "\"{$primary}\" is not a real provider; set AI_PROVIDER=anthropic or gemini");
        } else {
            $set = filled(config($keys[$primary]));
            $this->check($set, "Lumi key ({$primary})", 'set', 'missing; customers would only get the fallback text');
        }

        if (is_string($backup) && $backup !== '' && $backup !== $primary) {
            $set = isset($keys[$backup]) && filled(config($keys[$backup]));
            $this->check($set, "Lumi backup key ({$backup})", 'set', 'missing; the backup is skipped', 'WARN');
        }

        // Without a CA bundle every call fails with cURL error 60 on Windows hosts (docs/XAMPP_SETUP.md)
        $bundle = collect([$probe->ini('curl.cainfo'), $probe->ini('openssl.cafile')])->first(fn ($path) => is_string($path) && $probe->fileExists($path));
        $this->check($bundle !== null, 'CA bundle (curl.cainfo / openssl.cafile)', 'set and found', 'not set or file not found; AI calls fail with cURL error 60 on Windows hosts (docs/XAMPP_SETUP.md)', 'WARN');
    }

    private function caches(): void
    {
        $scheduled = count(app(Schedule::class)->events());
        $this->add('WARN', 'Scheduler', $scheduled === 0
            ? 'nothing is scheduled (audit:prune runs by hand); if a task is added later, add the cron entry for php artisan schedule:run'
            : "{$scheduled} scheduled task(s): make sure the host runs php artisan schedule:run every minute");

        $this->check(app()->configurationIsCached(), 'Config cache', 'cached', 'not cached; run php artisan config:cache after every .env change', 'WARN');
        $this->check(app()->routesAreCached(), 'Route cache', 'cached', 'not cached; run php artisan route:cache after deploying', 'WARN');
    }
}
