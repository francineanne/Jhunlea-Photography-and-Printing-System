<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The "must pay before printing" flag of a customer (users.prepay_required, Phase 10B). It is set when one of their
 * orders is marked Unclaimed (walk-in customers too) and cleared only by the owner; both are audited. While it is set,
 * every order the shop accepts for that customer is paid before printing (StatusTransitionService::prepay()).
 * The users row is locked after the order row (the order transaction's own lock order), and is never fillable.
 */
class PrepayFlagService
{
    /** Called by StatusTransitionService inside the Unclaimed transaction. Setting it twice writes nothing twice. */
    public function set(PrintingOrder $order, User $owner): void
    {
        $customer = User::query()->lockForUpdate()->find($order->customer_id);

        if ($customer === null || $customer->prepay_required) {
            return;
        }

        $customer->forceFill(['prepay_required' => true])->save();
        app(AuditLogger::class)->record(AuditAction::CustomerPrepaySet, $customer, ['for' => $order->reference_number], actor: $owner);
    }

    /** @throws AuthorizationException when the actor is not the owner */
    public function clear(User $customer, User $owner, ?string $reason = null): bool
    {
        if ($owner->role !== UserRole::Owner) {
            throw new AuthorizationException('Only the shop can clear this.');
        }

        return DB::transaction(function () use ($customer, $owner, $reason) {
            $row = User::query()->lockForUpdate()->findOrFail($customer->getKey());

            if (! $row->prepay_required) {
                return false;
            }

            $row->forceFill(['prepay_required' => false])->save();
            app(AuditLogger::class)->record(AuditAction::CustomerPrepayCleared, $row, ['reason' => $reason], actor: $owner);
            $customer->setRawAttributes($row->getAttributes(), true);

            return true;
        });
    }
}
