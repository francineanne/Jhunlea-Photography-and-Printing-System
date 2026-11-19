<?php

namespace App\Services\WalkIn;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\PhilippineMobile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        // Phase 10C: phones are stored as 09XXXXXXXXX, so "0917 555" and "+63 917 555" also find them by their digits
        $digits = (string) preg_replace('/\D/', '', (string) preg_replace('/^\+?63(?=9)/', '0', (string) preg_replace('/[\s\-.()]/', '', $term)));

        return User::query()
            ->where('role', UserRole::Customer)
            ->where(fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('phone', 'like', $like)
                ->when(strlen($digits) >= 3, fn (Builder $phone) => $phone->orWhere('phone', 'like', '%'.$digits.'%')))
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

    /**
     * Phase 10J: the owner edits a walk-in customer's name or phone (a walk-in only; an online customer edits their own
     * account). The phone is stored as 09XXXXXXXXX. Audited without the values.
     */
    public function update(User $customer, string $name, ?string $phone): User
    {
        $name = trim($name);

        if (! $customer->isWalkIn() || $name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidArgumentException('Only a walk-in customer with a name of up to '.self::NAME_MAX.' characters can be edited here.');
        }

        $phone = $phone === null || trim($phone) === '' ? null : PhilippineMobile::normalize($phone);
        $customer->forceFill(['name' => $name, 'phone' => $phone]);

        if ($customer->isDirty()) {
            DB::transaction(function () use ($customer) {
                $customer->save();
                app(AuditLogger::class)->record(AuditAction::WalkInCustomerUpdated, $customer);
            });
        }

        return $customer;
    }

    /** Phase 10J: a walk-in with no phone gets the one the owner typed for a booking (never replaces a saved one). */
    public function fillPhoneIfMissing(User $customer, string $phone): void
    {
        if ($customer->isWalkIn() && blank($customer->phone)) {
            $customer->forceFill(['phone' => PhilippineMobile::normalize($phone)])->save();
        }
    }

    /** The customer the owner picked; it must be a customer (walk-in or online), never an owner. */
    public function find(int $id): ?User
    {
        return User::query()->where('role', UserRole::Customer)->find($id);
    }
}
