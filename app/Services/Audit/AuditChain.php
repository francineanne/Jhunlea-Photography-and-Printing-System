<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Checks the audit trail's hash chain (Phase 8E). verify() walks every row in id order, in chunks, from the genesis or
 * from the newest prune checkpoint, and reports the first row whose hash or prev_hash does not match: a row edited,
 * deleted or reordered. quick() checks only the newest rows, for the Activity log page. This makes tampering
 * detectable, not impossible, for someone with direct database access.
 */
class AuditChain
{
    public const LAST_FULL = 'audit.verify.last';

    public const HEAD_MISMATCH = 'The newest rows were removed or the chain head was altered: the head does not match the newest row.';

    /** @return array{ok: bool, checked: int, broken_id: ?int, at: string, message: ?string} */
    public function verify(): array
    {
        [$expected, $after] = $this->start();
        $checked = 0;
        $broken = null;
        $message = null;

        DB::table('audit_logs')->where('id', '>', $after)->orderBy('id')->chunk((int) config('audit.verify_chunk'), function ($rows) use (&$expected, &$checked, &$broken) {
            foreach ($rows as $row) {
                $checked++;

                if ($row->prev_hash !== $expected || AuditLogger::hash((array) $row) !== $row->hash) {
                    $broken = (int) $row->id;

                    return false;
                }

                $expected = $row->hash;
            }
        });

        if ($broken !== null) {
            $message = "Row {$broken} or the one before it was edited, deleted or moved.";
        } elseif (($head = $this->headProblem()) !== null) {
            [$broken, $message] = $head;
        }

        $result = ['ok' => $message === null, 'checked' => $checked, 'broken_id' => $broken, 'at' => now('UTC')->toIso8601String(), 'message' => $message];
        Cache::forever(self::LAST_FULL, $result);

        return $result;
    }

    /**
     * The head row against the newest audit row: the walk alone cannot see the NEWEST rows being deleted (what is left
     * still chains), the head can. An empty table with a head that points nowhere is valid. After audit:prune the
     * newest row is the checkpoint, which the head points at, so it still matches.
     *
     * @return array{0: ?int, 1: string}|null the newest id and the message, or null when they match
     */
    private function headProblem(): ?array
    {
        $head = DB::table(AuditLogger::HEAD)->where('id', 1)->first();
        $newest = DB::table('audit_logs')->orderByDesc('id')->first(['id', 'hash']);

        if ($head === null) {
            return [$newest?->id === null ? null : (int) $newest->id, 'The chain head row is missing.'];
        }

        if ($newest === null) {
            return $head->last_id === null ? null : [null, self::HEAD_MISMATCH];
        }

        return (int) $head->last_id === (int) $newest->id && $head->last_hash === $newest->hash ? null : [(int) $newest->id, self::HEAD_MISMATCH];
    }

    /**
     * The newest rows only: each row's own hash, and each prev_hash against the row before it.
     *
     * @return array{ok: bool, checked: int, broken_id: ?int, message: ?string}
     */
    public function quick(?int $rows = null): array
    {
        $recent = DB::table('audit_logs')->orderByDesc('id')->limit(($rows ?? (int) config('audit.quick_check_rows')) + 1)->get()->reverse()->values();

        foreach ($recent as $i => $row) {
            $prevOk = $i === 0 || $row->prev_hash === $recent[$i - 1]->hash;

            if (! $prevOk || AuditLogger::hash((array) $row) !== $row->hash) {
                return ['ok' => false, 'checked' => $i + 1, 'broken_id' => (int) $row->id, 'message' => "Row {$row->id} or the one before it was edited, deleted or moved."];
            }
        }

        if (($head = $this->headProblem()) !== null) {
            return ['ok' => false, 'checked' => $recent->count(), 'broken_id' => $head[0], 'message' => $head[1]];
        }

        return ['ok' => true, 'checked' => $recent->count(), 'broken_id' => null, 'message' => null];
    }

    /** @return array{ok: bool, checked: int, broken_id: ?int, at: string}|null the last full check, if any */
    public function lastFull(): ?array
    {
        return Cache::get(self::LAST_FULL);
    }

    /**
     * Where the chain starts: the genesis, or the newest prune checkpoint (its anchor hash is the hash of the last pruned
     * row, and only rows after the pruned id are checked).
     *
     * @return array{0: string, 1: int}
     */
    private function start(): array
    {
        $checkpoint = DB::table('audit_logs')->where('action', AuditAction::AuditPruned->value)->orderByDesc('id')->first();

        if ($checkpoint === null) {
            return [AuditLogger::genesis(), 0];
        }

        $meta = json_decode((string) $checkpoint->meta, true) ?: [];

        return [(string) ($meta['anchor_hash'] ?? AuditLogger::genesis()), (int) ($meta['pruned_up_to_id'] ?? 0)];
    }
}
