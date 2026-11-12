<?php

namespace Tests\MySql\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Phase 9B: starts N real PHP processes (`php artisan jhunlea:race-worker`) that all begin their action at the same
 * moment, and returns what each one printed. Windows has no pcntl, so these are separate processes, each with its own
 * MySQL connection, exactly like two browser requests. The database settings are passed in the environment, taken
 * from the test's own (guarded) connection, never from .env; the worker checks the live database name again.
 */
final class Race
{
    /** Seconds between launching and the shared start: enough for every process to boot and connect on Windows. */
    public const LEAD_SECONDS = 9.0;

    /**
     * @param  list<array<string, mixed>>  $payloads  one per worker
     * @return list<array{worker: int, late: bool, results: list<array<string, mixed>>}>
     */
    public static function run(string $scenario, array $payloads, float $lead = self::LEAD_SECONDS, int $timeout = 180): array
    {
        return self::runMixed(array_map(fn ($payload) => [$scenario, $payload], $payloads), $lead, $timeout);
    }

    /**
     * Workers of different scenarios with one shared start (for example a payment and a price change).
     *
     * @param  list<array{0: string, 1: array<string, mixed>}>  $workers
     * @return list<array{worker: int, late: bool, results: list<array<string, mixed>>}>
     */
    public static function runMixed(array $workers, float $lead = self::LEAD_SECONDS, int $timeout = 180): array
    {
        $start = sprintf('%.6F', microtime(true) + $lead);
        $processes = [];

        foreach ($workers as $i => [$scenario, $payload]) {
            $process = new Process(
                [PHP_BINARY, '-d', 'memory_limit=512M', base_path('artisan'), 'jhunlea:race-worker', $scenario, '--start='.$start, '--worker='.$i, '--payload='.base64_encode((string) json_encode($payload))],
                base_path(),
                self::environment(),
                null,
                $timeout,
            );
            $process->start();
            $processes[$i] = $process;
            $names[$i] = $scenario;
        }

        $outputs = [];
        foreach ($processes as $i => $process) {
            $process->wait();
            $line = collect(preg_split('/\R/', trim($process->getOutput())))->last(fn ($line) => str_starts_with((string) $line, '{'));
            $decoded = $line === null ? null : json_decode($line, true);

            if (! is_array($decoded) || ! isset($decoded['results'])) {
                throw new RuntimeException("Worker {$i} ({$names[$i]}) printed no result. Exit {$process->getExitCode()}. "
                    .substr(trim($process->getErrorOutput().' '.$process->getOutput()), 0, 600));
            }

            $outputs[] = $decoded;
        }

        return $outputs;
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        $db = config('database.connections.'.config('database.default'));

        return [
            'APP_ENV' => 'testing',
            'JHUNLEA_MYSQL_TESTS' => '1',
            'DB_CONNECTION' => (string) config('database.default'),
            'DB_HOST' => (string) $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'],
            'DB_PASSWORD' => (string) $db['password'],
            'DB_URL' => '',
            'DB_SOCKET' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
            'FILESYSTEM_DISK' => 'local',
            'PRINTING_FILES_DISK' => 'local',
            'BOOKING_IMAGES_DISK' => 'local',
            'LOG_CHANNEL' => 'stderr',
            'AI_ENABLED' => 'false',
            'AI_PROVIDER' => 'fake',
            'AI_FALLBACK_PROVIDER' => 'none',
            'AI_API_KEY' => '',
            'AI_ANTHROPIC_API_KEY' => '',
            'ANTHROPIC_API_KEY' => '',
        ];
    }

    /**
     * Every result of every worker, flat.
     *
     * @param  list<array{results: list<array<string, mixed>>}>  $outputs
     * @return list<array<string, mixed>>
     */
    public static function results(array $outputs): array
    {
        return array_merge(...array_map(fn ($output) => $output['results'], $outputs));
    }
}
