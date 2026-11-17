<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10D: the "version stamp" behind the live updates. A page asks for it every few seconds; when the stamp is
 * different from the one it had, something this person can see has changed and the page refreshes its marked parts.
 *
 * Read only, a fixed handful of small aggregate queries, and nothing personal in the answer: the stamp is a hash.
 * A customer's stamp is made only from THEIR OWN orders, bookings, payments and notifications; the owner's from all
 * records plus the owner's own notifications.
 */
class LiveStamp
{
    public function __construct(private readonly NotificationFeed $feed) {}

    /**
     * @return array{stamp: string, unread: int, latest: array{message: string, url: ?string}|null}
     */
    public function for(User $user): array
    {
        $owner = $user->role === UserRole::Owner;
        $unread = $this->feed->unreadCount($user);

        $parts = [
            'orders' => $this->mark('printing_orders', $owner ? null : $user->id),
            'bookings' => $this->mark('photography_bookings', $owner ? null : $user->id),
            'payments' => $this->payments($owner ? null : $user->id),
            'notifications' => DB::table('notifications')->where('notifiable_type', 'user')->where('notifiable_id', $user->id)->selectRaw('COUNT(*) as total, MAX(created_at) as newest')->first(),
            'unread' => $unread,
        ];

        return [
            'stamp' => substr(hash('sha256', json_encode($parts)), 0, 24),
            'unread' => $unread,
            'latest' => $unread > 0 ? $this->latest($user) : null,
        ];
    }

    /** How many rows and when the newest change was: a new record, a status move and a removed record all change it. */
    private function mark(string $table, ?int $customerId): ?object
    {
        return DB::table($table)
            ->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
            ->selectRaw('COUNT(*) as total, MAX(updated_at) as newest, SUM(CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END) as removed')
            ->first();
    }

    /** Payments of the customer's own orders and bookings (every payment for the owner). */
    private function payments(?int $customerId): ?object
    {
        return DB::table('payments')
            ->when($customerId !== null, fn ($query) => $query->where(function ($own) use ($customerId) {
                $own->where(fn ($q) => $q->where('payable_type', 'printing_order')->whereIn('payable_id', DB::table('printing_orders')->where('customer_id', $customerId)->select('id')))
                    ->orWhere(fn ($q) => $q->where('payable_type', 'photography_booking')->whereIn('payable_id', DB::table('photography_bookings')->where('customer_id', $customerId)->select('id')));
            }))
            ->selectRaw('COUNT(*) as total, MAX(updated_at) as newest')
            ->first();
    }

    /**
     * The newest unread notification, worded by the one message map (NotificationFeed), for the toast.
     *
     * @return array{message: string, url: ?string}|null
     */
    private function latest(User $user): ?array
    {
        $item = $this->feed->bell($user)['items']->first(fn (array $row) => ! ($row['read'] ?? false));

        if ($item === null) {
            return null;
        }

        $url = $item['link'] ?? null;

        return ['message' => (string) ($item['message'] ?? $item['title'] ?? 'You have a new notification.'), 'url' => is_string($url) ? (parse_url($url, PHP_URL_PATH) ?: null) : null];
    }
}
