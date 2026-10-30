<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\ReadMessagesRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Requests\Chat\ThreadRequest;
use App\Models\Conversation;
use App\Services\Chat\ChatPresenter;
use App\Services\Chat\ConversationService;
use App\Services\Chat\LumiReplyService;
use App\Services\Chat\MessageService;
use App\Services\Chat\ThreadReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The customer's side of a conversation, JSON only (Phase 7B; the screens come in 7C and 7D): the studio thread, any of
 * their own Ask Lumi conversations, sending, asking Lumi to answer, and marking read. Another person's conversation
 * answers 404.
 */
class MessageController extends Controller
{
    public function __construct(
        private readonly ChatPresenter $presenter,
        private readonly MessageService $messages,
        private readonly ThreadReader $reader,
    ) {}

    /** The customer's one studio thread, created on first open, with the latest page. */
    public function studio(Request $request, ConversationService $conversations): JsonResponse
    {
        return $this->thread($request, $conversations->studioFor($request->user()));
    }

    /** The launcher's badge. Never creates the studio thread: a customer who never opened it simply has nothing unread. */
    public function unread(Request $request): JsonResponse
    {
        $studio = Conversation::query()->studio()->forCustomer($request->user())->first();

        return response()->json(['studio_unread' => $studio === null ? 0 : $this->messages->unreadCount($studio, $request->user())]);
    }

    public function show(ThreadRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        return $this->thread($request, $conversation, $request->afterId(), $request->beforeId());
    }

    public function store(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('send', $conversation);

        [$message, $created] = $this->messages->sendFromCustomer(
            $conversation, $request->user(), $request->body(), $request->clientId(), $request->about(), $request->fromAssistantConversation(),
        );

        return response()->json([
            'message' => $this->presenter->messages($conversation, $request->user(), collect([$message]))[0],
            'state' => $this->presenter->state($conversation, $request->user(), false),
        ], $created ? 201 : 200);
    }

    /** Lumi answers the newest customer message, when it may (see LumiReplyService). */
    public function lumi(Request $request, Conversation $conversation, LumiReplyService $lumi): JsonResponse
    {
        Gate::authorize('send', $conversation);

        $reply = $lumi->reply($conversation, $request->user());

        return response()->json([
            'status' => $reply->status,
            'messages' => $this->presenter->messages($conversation, $request->user(), collect($reply->messages)),
            'state' => $this->presenter->state($conversation->refresh(), $request->user(), false),
        ]);
    }

    public function read(ReadMessagesRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('read', $conversation);

        $this->messages->markRead($conversation, $request->user(), $request->upTo());

        return response()->json(['state' => $this->presenter->state($conversation->refresh(), $request->user(), false)]);
    }

    private function thread(Request $request, Conversation $conversation, ?int $after = null, ?int $before = null): JsonResponse
    {
        $page = $this->reader->page($conversation, $after, $before);

        return response()->json($this->presenter->thread($conversation, $request->user(), $page['messages'], $page['has_more_before'], $page['has_more_after']));
    }
}
