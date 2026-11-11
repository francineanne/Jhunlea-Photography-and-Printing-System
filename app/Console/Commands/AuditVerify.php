<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditChain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Walks the whole audit trail's hash chain and reports the first broken row (Phase 8E). Read only. */
#[Signature('audit:verify')]
#[Description('Check that no audit log row was edited, deleted or reordered')]
class AuditVerify extends Command
{
    public function handle(AuditChain $chain): int
    {
        $result = $chain->verify();

        if ($result['ok']) {
            $this->info("Chain intact: {$result['checked']} rows checked.");

            return self::SUCCESS;
        }

        $this->error('Chain broken'.($result['broken_id'] ? " at row {$result['broken_id']}" : '')." (after {$result['checked']} rows): {$result['message']}");

        return self::FAILURE;
    }
}
