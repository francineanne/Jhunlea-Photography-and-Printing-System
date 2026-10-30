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

/**
 * The owner's studio inbox and threads, JSON only (Phase 7B). Only studio threads: an Ask Lumi conversation answers 404.
 * Opening or polling the inbox or a thread, and replying, counts as the owner being active (OwnerPresence::touch).
 */
class MessageController extends Controller
{
    public function __construct(
        private readonly ChatPresenter $presenter,
        private readonly MessageService $messages,
        private readonly OwnerPresence $presence,
    ) {}

    public function index(InboxRequest $request, StudioInbox $inbox): JsonResponse
    {
        $this->presence->touch();
        $page = $inbox->page($request->filter(), $request->search());

        return response()->json([
            'conversations' => $page->getCollection()->map(fn (Conversation $conversation) => [
                'id' => $conversation->id,
                'customer' => ['name' => $conversation->customer->name],
                'preview' => $conversation->last_message_preview,
                'last_sender_type' => $conversation->last_sender_type?->value,
                'unread_count' => (int) $conversation->unread_count,
                'needs_owner' => $conversation->needs_owner,
                'needs_reply' => $conversation->last_sender_type === MessageSender::Customer,
                'last_message_at' => $this->presenter->time($conversation->last_message_at),
            ])->values(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'poll' => config('chat.poll'),
        ]);
    }

    public function show(ThreadRequest $request, Conversation $conversation, ThreadReader $reader): JsonResponse
    {
        Gate::authorize('view', $conversation);
        $this->presence->touch();

        $page = $reader->page($conversation, $request->afterId(), $request->beforeId());

        return response()->json($this->presenter->thread($conversation, $request->user(), $page['messages'], $page['has_more_before']));
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

    /** Clears "needs the owner" without sending a message. */
    public function resolve(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('resolve', $conversation);

        $this->messages->resolve($conversation);

        return response()->json(['state' => $this->presenter->state($conversation, $request->user(), false)]);
    }

    public function read(ReadMessagesRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('read', $conversation);

        $this->messages->markRead($conversation, $request->user(), $request->upTo());

        return response()->json(['state' => $this->presenter->state($conversation->refresh(), $request->user(), false)]);
    }
}
