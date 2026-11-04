<?php

namespace App\Http\Controllers\Owner;

use App\Enums\MessageSender;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\InboxRequest;
use App\Http\Requests\Chat\ReadMessagesRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Requests\Chat\ThreadRequest;
use App\Models\Conversation;
use App\Services\Chat\ChatPresenter;
use App\Services\Chat\MessageService;
use App\Services\Chat\OwnerPresence;
use App\Services\Chat\StudioInbox;
use App\Services\Chat\ThreadReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The owner's studio inbox and threads (Phase 7B JSON, 7D pages). The same address answers the page to a browser and
 * JSON to the page's own requests, so a notification link and a bookmark open the inbox. Only studio threads: an Ask
 * Lumi conversation answers 404. Opening the inbox or a thread, replying and resolving count as the owner being active
 * (OwnerPresence::touch); the background polls (?poll=1 on the inbox, ?after= or ?before= on a thread) do not.
 */
class MessageController extends Controller
{
    public function __construct(
        private readonly ChatPresenter $presenter,
        private readonly MessageService $messages,
        private readonly OwnerPresence $presence,
    ) {}

    public function index(InboxRequest $request, StudioInbox $inbox): JsonResponse|View
    {
        if (! $request->expectsJson()) {
            return $this->page($request, null);
        }

        if (! $request->isPoll()) {
            $this->presence->touch();
        }

        $page = $inbox->page($request->filter(), $request->search());

        return response()->json([
            'conversations' => $page->getCollection()->map(fn (Conversation $conversation) => [
                'id' => $conversation->id,
                'url' => route('owner.messages.show', $conversation),
                'customer' => ['name' => $conversation->customer->name],
                'preview' => $conversation->last_message_preview,
                'last_sender_type' => $conversation->last_sender_type?->value,
                'unread_count' => (int) $conversation->unread_count,
                'needs_owner' => $conversation->needs_owner,
                'needs_reply' => $conversation->last_sender_type === MessageSender::Customer,
                'resolved' => $conversation->isResolved(),
                'last_message_at' => $this->presenter->time($conversation->last_message_at),
            ])->values(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'unanswered' => $inbox->unansweredCount(),
            'poll' => config('chat.poll'),
        ]);
    }

    public function show(ThreadRequest $request, Conversation $conversation, ThreadReader $reader): JsonResponse|View
    {
        Gate::authorize('view', $conversation);

        if (! $request->expectsJson()) {
            return $this->page($request, $conversation);
        }

        if ($request->afterId() === null && $request->beforeId() === null) {
            $this->presence->touch();
        }

        $page = $reader->page($conversation, $request->afterId(), $request->beforeId());

        return response()->json($this->presenter->thread($conversation, $request->user(), $page['messages'], $page['has_more_before'], $page['has_more_after']));
    }

    public function store(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('send', $conversation);

        [$message, $created] = $this->messages->sendFromOwner($conversation, $request->user(), $request->body(), $request->clientId());

        return response()->json([
            'message' => $this->presenter->messages($conversation, $request->user(), collect([$message]))[0],
            'state' => $this->presenter->state($conversation, $request->user(), false),
        ], $created ? 201 : 200);
    }

    /** Marks the thread resolved and clears "needs the owner", without sending a message. */
    public function resolve(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('resolve', $conversation);

        $this->presence->touch();
        $this->messages->resolve($conversation);

        return $this->conversationState($request, $conversation);
    }

    public function reopen(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('resolve', $conversation);

        $this->presence->touch();
        $this->messages->reopen($conversation);

        return $this->conversationState($request, $conversation);
    }

    public function read(ReadMessagesRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('read', $conversation);

        $this->messages->markRead($conversation, $request->user(), $request->upTo());

        return response()->json(['state' => $this->presenter->state($conversation->refresh(), $request->user(), false)]);
    }

    private function conversationState(Request $request, Conversation $conversation): JsonResponse
    {
        return response()->json([
            'conversation' => $this->presenter->conversation($conversation, $request->user()),
            'state' => $this->presenter->state($conversation, $request->user(), false),
        ]);
    }

    /** The inbox page, with a thread open when one is given. The lists and the thread load through the JSON above. */
    private function page(InboxRequest|ThreadRequest $request, ?Conversation $conversation): View
    {
        $this->presence->touch();

        return view('owner.messages.index', [
            'conversation' => $conversation?->loadMissing('customer:id,name'),
            'filter' => $request instanceof InboxRequest ? ($request->filter() ?? 'all') : 'all',
            'search' => $request instanceof InboxRequest ? $request->search() : null,
        ]);
    }
}
