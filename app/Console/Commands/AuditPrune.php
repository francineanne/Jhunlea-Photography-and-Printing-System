<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Services\Audit\AuditChain;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deletes audit rows older than config audit.retention_days (Phase 8E). Never scheduled: the developer runs it. It first
 * checks the chain, then deletes the old rows and writes one checkpoint row ("pruned up to row N", with the hash of row N
 * as the anchor), so `audit:verify` can still check every row that is left.
 */
#[Signature('audit:prune {--dry-run : Only say what would be deleted} {--force : Really delete}')]
#[Description('Delete audit log rows older than the retention limit, leaving a checkpoint row')]
class AuditPrune extends Command
{
    public function handle(AuditChain $chain, AuditLogger $logger): int
    {
        $days = (int) config('audit.retention_days');
        $cutoff = Carbon::now('UTC')->subDays($days);
        $last = DB::table('audit_logs')->where('occurred_at', '<', $cutoff)->orderByDesc('id')->first(['id', 'hash']);
        $count = $last === null ? 0 : DB::table('audit_logs')->where('id', '<=', $last->id)->count();

        $this->line("Retention: {$days} days. Rows older than that: {$count}".($last ? " (up to row {$last->id})." : '.'));

        if ($count === 0 || $this->option('dry-run') || ! $this->option('force')) {
            if ($count > 0 && ! $this->option('dry-run')) {
                $this->warn('Nothing deleted. Run again with --force to delete them.');
            }

            return self::SUCCESS;
        }

        if (! $chain->verify()['ok']) {
            $this->error('The chain is broken; run audit:verify and look into it before pruning. Nothing deleted.');

            return self::FAILURE;
        }

        // The checkpoint first (it chains to the newest row), then the delete: the first row left after row N always
        // has prev_hash = the hash of row N, the anchor, even when every row was old enough to go
        DB::transaction(function () use ($last, $count, $logger) {
            $logger->record(AuditAction::AuditPruned, null, ['pruned_up_to_id' => (int) $last->id, 'anchor_hash' => $last->hash, 'rows' => $count]);
            DB::table('audit_logs')->where('id', '<=', $last->id)->delete();
        });

        $this->info("Deleted {$count} rows and wrote a checkpoint.");

        return self::SUCCESS;
    }
}
