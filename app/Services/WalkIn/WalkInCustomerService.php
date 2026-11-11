<?php

namespace App\Services\WalkIn;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Walk-in customers (Phase 8A): people the owner serves at the counter, with no login. A walk-in is an ordinary
 * customer row with is_walk_in = true and no email or password, so it can never sign in, reset a password or be
 * notified. The owner searches first (name or phone) and picks an existing customer to avoid duplicates; two people
 * may share a name, so the picker shows the phone.
 */
class WalkInCustomerService
{
    public const NAME_MAX = 80;

    public const SEARCH_LIMIT = 8;

    /**
     * Customers matching a name or phone, walk-ins and online customers alike (an online customer may come to the
     * counter too); newest first.
     *
     * @return Collection<int, User>
     */
    public function search(string $term): Collection
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        // Bound parameters; % _ \ typed by the owner are matched literally
        $like = '%'.addcslashes($term, '%_\\').'%';

        return User::query()
            ->where('role', UserRole::Customer)
            ->where(fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('phone', 'like', $like))
            ->latest('id')
            ->limit(self::SEARCH_LIMIT)
            ->get(['id', 'name', 'phone', 'email', 'is_walk_in']);
    }

    /** A new walk-in customer: a name, and a phone when given. Never an email or a password. */
    public function create(string $name, ?string $phone): User
    {
        $name = trim($name);
        $phone = $phone === null || trim($phone) === '' ? null : trim($phone);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidArgumentException('A walk-in customer needs a name of up to '.self::NAME_MAX.' characters.');
        }

        $customer = new User;
        $customer->forceFill([
            'name' => $name,
            'phone' => $phone,
            'email' => null,
            'password' => null,
            'role' => UserRole::Customer,
            'is_walk_in' => true,
        ])->save();

        // Audit trail (8E): inside the counter form's transaction; the name only (the owner sees it), never the phone
        app(AuditLogger::class)->record(AuditAction::WalkInCustomerCreated, $customer);

        return $customer;
    }

    /** The customer the owner picked; it must be a customer (walk-in or online), never an owner. */
    public function find(int $id): ?User
    {
        return User::query()->where('role', UserRole::Customer)->find($id);
    }
}
