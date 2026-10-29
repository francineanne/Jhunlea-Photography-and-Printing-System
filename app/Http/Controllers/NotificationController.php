<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Notifications\NotificationListRequest;
use App\Services\NotificationFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

/**
 * The notifications page of the customer and the owner area (Phase 5B), and reading or marking one. A person only ever
 * finds their own rows (another id answers 404). Marking is always a POST; the GET link only leads the way.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationFeed $feed) {}

    public function index(NotificationListRequest $request): View
    {
        $user = $request->user();
        $unreadOnly = $request->unreadOnly();
        $page = $this->feed->page($user, $unreadOnly);

        return view($this->area($request).'.notifications.index', [
            'items' => $page->getCollection()->map(fn (DatabaseNotification $row) => $this->feed->present($user, $row))->filter()->values(),
            'page' => $page,
            'counts' => $this->feed->counts($user),
            'unreadOnly' => $unreadOnly,
            'area' => $this->area($request),
        ]);
    }

    /** Marks it read, then goes to the order or booking. */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $row = $this->feed->find($request->user(), $notification);
        $row->markAsRead();

        return $this->follow($request, $row);
    }

    /** The plain link fallback: goes to the order or booking without marking anything. */
    public function go(Request $request, string $notification): RedirectResponse
    {
        return $this->follow($request, $this->feed->find($request->user(), $notification));
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $this->feed->find($request->user(), $notification)->markAsRead();

        return redirect()->back(fallback: $this->indexUrl($request));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $count = $this->feed->markAllRead($request->user());

        return redirect()->back(fallback: $this->indexUrl($request))
            ->with('status', $count === 0 ? 'Everything was already read.' : 'All notifications are marked as read.');
    }

    private function follow(Request $request, DatabaseNotification $row): RedirectResponse
    {
        $target = $this->feed->target($request->user(), $row);

        return $target === null
            ? redirect()->to($this->indexUrl($request))->with('error', 'That order or booking is no longer available to you, so there is nothing to open.')
            : redirect()->to($target);
    }

    private function area(Request $request): string
    {
        return $request->user()->role === UserRole::Owner ? 'owner' : 'customer';
    }

    private function indexUrl(Request $request): string
    {
        return route($this->area($request).'.notifications.index');
    }
}
