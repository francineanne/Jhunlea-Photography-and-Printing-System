<?php

namespace App\Services;

use App\Enums\NotificationKind;
use App\Enums\UserRole;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * What the bell and the notifications pages read (Phase 5B), always for one person and only their own rows. Every item
 * is presented through NotificationKind (the one message map); the link to the order or booking is built from the
 * stored type and id, without a query per item.
 */
class NotificationFeed
{
    public const DROPDOWN = 8;

    public const PER_PAGE = 20;

    /** Above this the badge says "99+". */
    public const BADGE_MAX = 99;

    /**
     * The bell: the unread count (one query) and the latest few (one query).
     *
     * @return array{unread: int, items: Collection<int, array<string, mixed>>}
     */
    public function bell(User $user): array
    {
        return [
            'unread' => $this->unreadCount($user),
            'items' => $user->notifications()->latest()->limit(self::DROPDOWN)->get()->map(fn (DatabaseNotification $row) => $this->present($user, $row))->filter()->values(),
        ];
    }

    /** One query, also used by the dashboards. reorder(): MySQL refuses ORDER BY next to COUNT. */
    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->reorder()->count();
    }

    /** @return LengthAwarePaginator<int, DatabaseNotification> */
    public function page(User $user, bool $unreadOnly): LengthAwarePaginator
    {
        return ($unreadOnly ? $user->unreadNotifications() : $user->notifications())
            ->latest()
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /** @return array{all: int, unread: int} */
    public function counts(User $user): array
    {
        // reorder(): the relation sorts by date, and MySQL refuses ORDER BY next to these aggregates
        $row = $user->notifications()->reorder()->toBase()->selectRaw('COUNT(*) as total, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread')->first();

        return ['all' => (int) ($row->total ?? 0), 'unread' => (int) ($row->unread ?? 0)];
    }

    /** The person's own notification, or 404: another person's id is never found. */
    public function find(User $user, string $id): DatabaseNotification
    {
        return $user->notifications()->whereKey($id)->firstOrFail();
    }

    /** Marks every unread one read; a second time changes nothing. */
    public function markAllRead(User $user): int
    {
        return $user->unreadNotifications()->reorder()->update(['read_at' => now()]);
    }

    /**
     * Where the notification leads, if the order or booking still exists and this person may see it; null otherwise.
     */
    public function target(User $user, DatabaseNotification $row): ?string
    {
        $data = $row->data;
        $class = Relation::getMorphedModel((string) ($data['payable_type'] ?? ''));

        if (! in_array($class, [PrintingOrder::class, PhotographyBooking::class], true)) {
            return null;
        }

        $model = $class::query()->find((int) ($data['payable_id'] ?? 0));

        if ($model === null || Gate::forUser($user)->denies('view', $model)) {
            return null;
        }

        return $this->url($user, $data);
    }

    /**
     * One notification as the views show it, or null for a kind this version does not know.
     *
     * @return array{id: string, kind: NotificationKind, title: string, message: string, reference: string, icon: string, read: bool, created_at: mixed, link: string}|null
     */
    public function present(User $user, DatabaseNotification $row): ?array
    {
        $data = is_array($row->data) ? $row->data : [];
        $kind = NotificationKind::tryFrom((string) ($data['kind'] ?? ''));

        if ($kind === null) {
            return null;
        }

        return [
            'id' => $row->id,
            'kind' => $kind,
            'title' => $kind->title(),
            'message' => $kind->message($data),
            'reference' => (string) ($data['reference'] ?? ''),
            'icon' => $kind->icon(),
            'read' => $row->read_at !== null,
            'created_at' => $row->created_at,
            'link' => $this->url($user, $data),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function url(User $user, array $data): string
    {
        $owner = $user->role === UserRole::Owner;
        $id = (int) ($data['payable_id'] ?? 0);
        $anchor = NotificationKind::tryFrom((string) ($data['kind'] ?? ''))?->anchor() ?? '';

        return match ($data['payable_type'] ?? null) {
            'printing_order' => route($owner ? 'owner.orders.show' : 'customer.printing.show', $id),
            'photography_booking' => route($owner ? 'owner.bookings.show' : 'customer.bookings.show', $id),
            default => route($owner ? 'owner.notifications.index' : 'customer.notifications.index'),
        }.$anchor;
    }
}
