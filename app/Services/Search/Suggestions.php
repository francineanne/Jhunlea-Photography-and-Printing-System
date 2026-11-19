<?php

namespace App\Services\Search;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Phase 10F: the suggestions under every search box (<x-ui.typeahead>). Read only, at most 8, only the fields the
 * dropdown shows. Each scope reuses its page's own search (the same columns, % _ \ matched literally as since 9B):
 * - one character: the START of a word only ("a" finds "Ana Reyes" and "Maria Ana", not "Santos");
 * - two or more: anywhere in the text.
 * Ordered by relevance: a name or reference that STARTS with the text first, then the most recent.
 *
 * Who may see what is decided by the route (owner, signed-in customer, public) AND here: the customer scope reads only
 * the given customer's own records; the public scopes read only published catalog items.
 *
 * Each item: label, sub (the grey second line), type (a small label), url (where Enter or a click goes) and, for
 * "message", post (a form to submit instead of a link: starting a conversation is a POST).
 */
class Suggestions
{
    public const LIMIT = 8;

    public const OWNER_SCOPES = ['message', 'customers', 'payments', 'orders', 'bookings', 'activity', 'walk_in'];

    public const PUBLIC_SCOPES = ['services', 'packages'];

    /** @return list<array<string, mixed>> */
    public function owner(string $scope, string $term): array
    {
        return match ($scope) {
            'message' => $this->customers($term, false, true),
            'customers' => $this->customers($term, true, false),
            // Phase 10J: "Find a walk-in customer" on the Walk-in page
            'walk_in' => $this->customers($term, true, false, onlyWalkIns: true),
            'payments' => $this->payable($term, 'payments'),
            'orders' => $this->payable($term, 'orders'),
            'bookings' => $this->payable($term, 'bookings'),
            'activity' => $this->activity($term),
            default => [],
        };
    }

    /** @return list<array<string, mixed>> the signed-in customer's OWN orders and bookings only */
    public function customer(User $customer, string $term): array
    {
        $orders = PrintingOrder::query()->where('customer_id', $customer->id)->with('service:id,name')
            ->where(fn (Builder $q) => $this->match($q, 'reference_number', $term)->orWhereHas('service', fn (Builder $s) => $this->match($s, 'name', $term)))
            ->latest('id')->limit(self::LIMIT)->get();
        $bookings = PhotographyBooking::query()->where('customer_id', $customer->id)->with('package:id,name')
            ->where(fn (Builder $q) => $this->match($q, 'reference_number', $term)->orWhereHas('package', fn (Builder $p) => $this->match($p, 'name', $term)))
            ->latest('id')->limit(self::LIMIT)->get();

        return $this->rank(collect([...$orders, ...$bookings]), $term, fn ($record) => $record->reference_number)
            ->map(fn ($record) => [
                'label' => $record->reference_number,
                'sub' => ($record instanceof PrintingOrder ? $record->serviceName() : (string) ($record->snapshot['package']['name'] ?? $record->package?->name ?? 'Photoshoot')).' · '.($record instanceof PhotographyBooking ? $record->statusLabel() : $record->status->label()),
                'type' => $record instanceof PrintingOrder ? 'Order' : 'Booking',
                'url' => $record instanceof PrintingOrder ? route('customer.printing.show', $record) : route('customer.bookings.show', $record),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> published catalog items only */
    public function catalog(string $scope, string $term): array
    {
        $model = $scope === 'packages' ? PhotographyPackage::class : PrintingService::class;
        $rows = $model::query()->where('is_published', true)->where(fn (Builder $q) => $this->match($q, 'name', $term))
            ->orderBy('name')->limit(self::LIMIT * 2)->get(['id', 'name', 'slug', 'category']);

        return $this->rank($rows, $term, fn ($row) => $row->name)->map(fn ($row) => [
            'label' => $row->name,
            'sub' => (string) $row->category,
            'type' => $scope === 'packages' ? 'Package' : 'Service',
            'url' => $scope === 'packages' ? route('packages.show', $row->slug) : route('services.index', ['q' => $row->name]),
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function customers(string $term, bool $walkIns, bool $forMessage, bool $onlyWalkIns = false): array
    {
        $digits = preg_replace('/\D/', '', $term);

        $rows = User::query()->where('role', UserRole::Customer)
            ->when(! $walkIns, fn (Builder $q) => $q->where('is_walk_in', false))
            ->when($onlyWalkIns, fn (Builder $q) => $q->where('is_walk_in', true))
            ->where(function (Builder $q) use ($term, $digits) {
                $this->match($q, 'name', $term);
                if (mb_strlen($term) >= 2) {
                    $q->orWhere('email', 'like', '%'.self::escape($term).'%');
                }
                if (strlen((string) $digits) >= 3) {
                    $q->orWhere('phone', 'like', '%'.$digits.'%');
                }
            })
            ->withCount(['printingOrders', 'photographyBookings'])
            ->orderByDesc('updated_at')->limit(self::LIMIT * 2)
            ->get(['id', 'name', 'email', 'phone', 'is_walk_in', 'email_verified_at', 'updated_at']);

        return $this->rank($rows, $term, fn (User $user) => $user->name)->map(function (User $user) use ($forMessage) {
            $requests = (int) $user->printing_orders_count + (int) $user->photography_bookings_count;

            return array_filter([
                'label' => $user->name,
                'sub' => implode(' · ', array_filter([
                    $user->is_walk_in ? 'Walk-in' : null,
                    $user->phone,
                    $requests.' '.($requests === 1 ? 'request' : 'requests'),
                    ! $user->is_walk_in && ! $user->hasVerifiedEmail() ? 'Email not verified' : null,
                ])),
                'type' => $user->is_walk_in ? 'Walk-in' : 'Customer',
                'url' => route('owner.customers.show', $user),
                'post' => $forMessage ? ['url' => route('owner.messages.start'), 'fields' => ['customer' => $user->id]] : null,
            ]);
        })->values()->all();
    }

    /** Orders and bookings by reference, customer name or phone (the counter's and the lists' own search). */
    private function payable(string $term, string $where): array
    {
        $compact = (string) preg_replace('/^(P[RB])[\s-]*/i', '$1-', trim($term));
        $people = fn (Builder $c) => $c->where(fn (Builder $inner) => $this->match($inner, 'name', $term)->when(mb_strlen($term) >= 3, fn ($q) => $q->orWhere('phone', 'like', '%'.self::escape($term).'%')));
        $rows = collect();

        if ($where !== 'bookings') {
            $rows = $rows->concat(PrintingOrder::query()->with('customer:id,name,phone')
                ->where(fn (Builder $q) => $this->match($q, 'reference_number', $compact)->orWhereHas('customer', $people))
                ->latest('id')->limit(self::LIMIT)->get());
        }
        if ($where !== 'orders') {
            $rows = $rows->concat(PhotographyBooking::query()->with('customer:id,name,phone')
                ->where(fn (Builder $q) => $this->match($q, 'reference_number', $compact)
                    ->when(mb_strlen($term) >= 3, fn ($inner) => $inner->orWhere('contact_number', 'like', '%'.self::escape($term).'%'))
                    ->orWhereHas('customer', $people))
                ->latest('id')->limit(self::LIMIT)->get());
        }

        return $this->rank($rows, $term, fn ($record) => $record->customer?->name ?? $record->reference_number, fn ($record) => $record->reference_number)
            ->map(function ($record) use ($where) {
                $isOrder = $record instanceof PrintingOrder;

                return [
                    'label' => $record->reference_number.' · '.($record->customer?->name ?? 'Deleted account'),
                    'sub' => ($isOrder ? $record->serviceName() : (string) ($record->snapshot['package']['name'] ?? 'Photoshoot')).' · '.($isOrder ? $record->status->label() : $record->statusLabel()),
                    'type' => $isOrder ? 'Order' : 'Booking',
                    // On the payments page a suggestion opens the counter on that record; elsewhere its own page
                    'url' => $where === 'payments'
                        ? route('owner.payments.index', ['pay' => $record->getMorphClass().':'.$record->id])
                        : ($isOrder ? route('owner.orders.show', $record) : route('owner.bookings.show', $record)),
                ];
            })->values()->all();
    }

    /** What the activity log can be searched for: the records and the people its rows name. */
    private function activity(string $term): array
    {
        $rows = AuditLog::query()
            ->where(fn (Builder $q) => $this->match($q, 'subject_label', $term)->orWhere(fn (Builder $a) => $this->match($a, 'actor_name', $term)))
            ->latest('id')->limit(50)->get(['subject_label', 'actor_name', 'occurred_at']);

        $values = $rows->flatMap(fn (AuditLog $row) => [$row->subject_label, $row->actor_name])
            ->filter(fn ($value) => is_string($value) && $value !== '' && self::matches($value, $term))
            ->unique()->values();

        return $this->rank($values, $term, fn (string $value) => $value)->map(fn (string $value) => [
            'label' => $value,
            'sub' => 'Show its activity',
            'type' => 'Activity',
            'url' => route('owner.activity.index', ['q' => $value, 'range' => 'all']),
        ])->values()->all();
    }

    /** One character: the start of a word; two or more: anywhere. */
    private function match(Builder $query, string $column, string $term): Builder
    {
        $term = trim($term);
        $escaped = self::escape($term);

        if (mb_strlen($term) < 2) {
            return $query->where(fn (Builder $q) => $q->where($column, 'like', $escaped.'%')->orWhere($column, 'like', '% '.$escaped.'%')->orWhere($column, 'like', '%-'.$escaped.'%'));
        }

        return $query->where($column, 'like', '%'.$escaped.'%');
    }

    private static function matches(string $value, string $term): bool
    {
        $term = mb_strtolower(trim($term));
        $value = mb_strtolower($value);

        return mb_strlen($term) < 2 ? (bool) preg_match('/(^|[\s-])'.preg_quote($term, '/').'/u', $value) : str_contains($value, $term);
    }

    private static function escape(string $term): string
    {
        return addcslashes($term, '%_\\');
    }

    /**
     * Starts-with first (on the name, or the second key such as the reference), then the order given (most recent).
     *
     * @param  Collection<int, mixed>  $rows
     */
    private function rank(Collection $rows, string $term, callable $key, ?callable $alsoKey = null): Collection
    {
        $term = mb_strtolower(trim($term));
        $starts = fn ($row) => str_starts_with(mb_strtolower((string) $key($row)), $term) || ($alsoKey !== null && str_starts_with(mb_strtolower((string) $alsoKey($row)), $term));

        return $rows->values()->map(fn ($row, int $index) => [$row, $starts($row) ? 0 : 1, $index])
            ->sortBy([[1, 'asc'], [2, 'asc']])
            ->map(fn (array $pair) => $pair[0])
            ->take(self::LIMIT)->values();
    }
}
