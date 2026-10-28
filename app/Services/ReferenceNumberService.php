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

    public function next(string $prefix = self::PRINTING): string
    {
        return DB::transaction(function () use ($prefix) {
            // Make sure the row exists, then lock it. insertOrIgnore keeps two first-ever requests from colliding
            DB::table('reference_counters')->insertOrIgnore(['prefix' => $prefix, 'last_number' => 0]);

            $last = (int) DB::table('reference_counters')->where('prefix', $prefix)->lockForUpdate()->value('last_number');
            $number = $last + 1;

            DB::table('reference_counters')->where('prefix', $prefix)->update(['last_number' => $number]);

            return $this->format($prefix, $number);
        });
    }

    public function format(string $prefix, int $number): string
    {
        return $prefix.'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
