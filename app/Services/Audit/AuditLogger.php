<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Assistant\Redactor;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The ONE writer of the audit trail (Phase 8E). Services call record() INSIDE the transaction of the action, after the
 * change succeeded: a rolled-back action leaves no row, and a failure to write the row fails the action (an unlogged
 * payment is worse than a retry). Outside a transaction (sign-in, a CSV, a receipt) it runs in its own transaction and
 * a failure is only logged. Each row is chained: hash = SHA-256 of the previous row's hash and this row's fixed fields.
 * The previous hash is read from the single `audit_chain_head` row, locked for the transaction and moved forward in it,
 * so two writes can never fork the chain (also on an empty table) and there is no gap lock on audit_logs.
 */
class AuditLogger
{
    public const REASON_MAX = 200;

    /** The one-row table that holds the newest row's id and hash, and is the write lock. */
    public const HEAD = 'audit_chain_head';

    public function __construct(private readonly Redactor $redactor) {}

    /** @param array<string, mixed> $meta only the action's whitelisted keys are kept */
    public function record(AuditAction $action, ?Model $subject = null, array $meta = [], ?string $summary = null, ?User $actor = null, ?string $subjectLabel = null): ?AuditLog
    {
        $actor ??= Auth::user();
        $meta = $this->clean(array_intersect_key($meta, array_flip($action->metaKeys())));
        $label = $subjectLabel ?? ($subject !== null ? AuditSubject::label($subject) : null);

        $row = [
            'occurred_at' => Carbon::now('UTC')->startOfSecond(),
            'actor_id' => $actor?->getKey(),
            'actor_name' => mb_substr($actor !== null ? (string) $actor->name : 'System', 0, 120),
            'actor_role' => $actor !== null ? $actor->role->value : 'system',
            'action' => $action,
            'subject_type' => $subject !== null ? AuditSubject::type($subject) : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $label === null ? null : $this->redactor->redact($label),
            'summary' => mb_substr($this->redactor->redact($summary ?? AuditSentence::make($action, $label, $meta)), 0, 255),
            'meta' => $meta === [] ? null : $meta,
            'ip' => App::runningInConsole() ? null : request()->ip(),
        ];

        $write = function () use ($row): AuditLog {
            // The single head row is the lock: every writer waits here, so two writes never read the same prev_hash
            $head = $this->lockHead();
            $row['prev_hash'] = $head->last_hash;
            $row['hash'] = self::hash($row);

            $log = new AuditLog;
            $log->forceFill($row)->save();

            DB::table(self::HEAD)->where('id', 1)->update(['last_id' => $log->getKey(), 'last_hash' => $row['hash']]);

            return $log;
        };

        if ($this->inTransaction()) {
            return $write();
        }

        try {
            return DB::transaction($write);
        } catch (Throwable $e) {
            // Never the row's content: the action and the kind of failure only
            Log::error('audit.write_failed', ['action' => $action->value, 'error' => $e::class]);

            return null;
        }
    }

    /**
     * The chain head (id 1), locked for this transaction. The migration creates it; if it is missing anyway it is
     * created with the genesis hash (insertOrIgnore, so two writers cannot both create it) instead of crashing. When
     * rows already exist, audit:verify then reports the head as broken, which is right: someone removed it.
     */
    private function lockHead(): object
    {
        $head = DB::table(self::HEAD)->where('id', 1)->lockForUpdate()->first();

        if ($head === null) {
            DB::table(self::HEAD)->insertOrIgnore(['id' => 1, 'last_id' => null, 'last_hash' => self::genesis()]);
            $head = DB::table(self::HEAD)->where('id', 1)->lockForUpdate()->first();
        }

        return $head;
    }

    /** Inside the action's own transaction? (Protected so a test can take the outside path: tests always run in one.) */
    protected function inTransaction(): bool
    {
        return DB::transactionLevel() > 0;
    }

    /** A catalog change (service, option, package, theme): the kind and the name, nothing else. */
    public function catalog(AuditAction $action, Model $item, string $kind): ?AuditLog
    {
        return $this->record($action, $item, ['kind' => $kind, 'name' => AuditSubject::label($item)]);
    }

    /** Published or hidden, from the item's new state. */
    public function published(Model $item, string $kind): ?AuditLog
    {
        return $this->catalog($item->getAttribute('is_published') ? AuditAction::CatalogPublished : AuditAction::CatalogUnpublished, $item, $kind);
    }

    public static function genesis(): string
    {
        return hash('sha256', (string) config('audit.genesis'));
    }

    /**
     * The hash of a row: SHA-256 over its prev_hash and the canonical JSON of its fixed fields (keys sorted, so the
     * database's own JSON key order never matters).
     *
     * @param  array<string, mixed>  $row
     */
    public static function hash(array $row): string
    {
        $occurred = $row['occurred_at'] instanceof \DateTimeInterface ? Carbon::instance($row['occurred_at'])->utc()->format('Y-m-d H:i:s') : (string) $row['occurred_at'];
        $action = $row['action'] instanceof AuditAction ? $row['action']->value : (string) $row['action'];

        $fields = [
            'occurred_at' => $occurred,
            'actor_id' => $row['actor_id'] === null ? null : (int) $row['actor_id'],
            'actor_name' => (string) $row['actor_name'],
            'actor_role' => (string) $row['actor_role'],
            'action' => $action,
            'subject_type' => $row['subject_type'],
            'subject_id' => $row['subject_id'] === null ? null : (int) $row['subject_id'],
            'subject_label' => $row['subject_label'],
            'summary' => (string) $row['summary'],
            'meta' => self::canonical(is_string($row['meta'] ?? null) ? json_decode($row['meta'], true) : ($row['meta'] ?? null)),
            'ip' => $row['ip'] ?? null,
        ];

        return hash('sha256', $row['prev_hash'].'|'.json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Sorts every associative array by key, recursively. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map([self::class, 'canonical'], $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Strings are redacted (a phone or an email typed into a reason never stays) and cut to REASON_MAX; nested arrays
     * the same; numbers and booleans as they are.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function clean(array $meta): array
    {
        foreach ($meta as $key => $value) {
            // A checkpoint's anchor is a SHA-256 hex string: kept exactly (the redactor would read digit runs in it)
            if ($key === 'anchor_hash') {
                $meta[$key] = preg_match('/^[0-9a-f]{64}$/', (string) $value) ? (string) $value : null;

                continue;
            }

            $meta[$key] = match (true) {
                is_string($value) => mb_substr($this->redactor->redact(trim($value)), 0, self::REASON_MAX),
                is_array($value) => $this->clean($value),
                is_int($value), is_bool($value), $value === null => $value,
                default => mb_substr($this->redactor->redact((string) $value), 0, self::REASON_MAX),
            };
        }

        return $meta;
    }

    /** Whole centavos of a decimal string (for meta). */
    public static function cents(?string $amount): ?int
    {
        return $amount === null ? null : Money::toCents($amount);
    }
}
