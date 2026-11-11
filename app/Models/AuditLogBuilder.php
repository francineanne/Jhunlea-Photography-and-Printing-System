<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * The Eloquent builder of AuditLog (Phase 8E): mass update and delete are refused, so the only ways to change the trail
 * are direct SQL (which `audit:verify` detects) and `audit:prune --force` (which writes a checkpoint row).
 *
 * @extends Builder<AuditLog>
 */
class AuditLogBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException('Audit log rows cannot be changed.');
    }

    public function delete()
    {
        throw new LogicException('Audit log rows cannot be deleted.');
    }

    public function forceDelete()
    {
        throw new LogicException('Audit log rows cannot be deleted.');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('Audit log rows cannot be changed.');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('Audit log rows cannot be changed.');
    }
}
