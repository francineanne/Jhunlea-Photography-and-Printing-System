<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Hands out reference numbers such as PR-000001, one after the other with none repeated.
 *
 * Each prefix has one row in reference_counters. That row is locked (SELECT ... FOR UPDATE) until the surrounding
 * transaction ends, so two requests placing an order at the same moment take turns. Call next() inside the same
 * transaction that saves the order: if the order fails to save, the number is given back and no gap is left.
 */
class ReferenceNumberService
{
    public const PRINTING = 'PR';

    public const BOOKING = 'PB';

    public const PAYMENT = 'PAY';

    public function next(string $prefix = self::PRINTING): string
    {
        return DB::transaction(function () use ($prefix) {
            $number = $this->lockedLast($prefix) + 1;

            DB::table('reference_counters')->where('prefix', $prefix)->update(['last_number' => $number]);

            return $this->format($prefix, $number);
        });
    }

    /**
     * Locks a prefix's counter row for the caller's transaction without taking a number (Phase 9B). A transaction that
     * will need a number of this prefix only AFTER it has locked something else (the audit chain head) takes this lock
     * first, so every transaction locks the counter before the head and two of them can never wait on each other.
     */
    public function lock(string $prefix): void
    {
        $this->lockedLast($prefix);
    }

    /**
     * The counter row, locked (SELECT ... FOR UPDATE), created first when the prefix has none yet. Lock FIRST, insert
     * only when missing (Phase 9B, found on MySQL): an INSERT IGNORE on an existing row takes a SHARED lock on it, and
     * two transactions holding that shared lock both waiting for the exclusive one is a deadlock (1213) every time two
     * numbers of a prefix are asked for at once. Only the very first number of a prefix can still race; insertOrIgnore
     * keeps that one from failing.
     */
    private function lockedLast(string $prefix): int
    {
        $last = DB::table('reference_counters')->where('prefix', $prefix)->lockForUpdate()->value('last_number');

        if ($last === null) {
            DB::table('reference_counters')->insertOrIgnore(['prefix' => $prefix, 'last_number' => 0]);
            $last = DB::table('reference_counters')->where('prefix', $prefix)->lockForUpdate()->value('last_number');
        }

        return (int) $last;
    }

    public function format(string $prefix, int $number): string
    {
        return $prefix.'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
