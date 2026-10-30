<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\Chat\ChatPresenter;
use App\Services\Chat\ConversationService;
use App\Services\Chat\MessageService;
use App\Services\Chat\ThreadReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The customer's Ask Lumi conversations (Phase 7B): the list, starting a new one, and the "contact the studio" draft. */
class AssistantConversationController extends Controller
{
    public function __construct(private readonly ChatPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $page = Conversation::query()->assistant()->forCustomer($request->user())
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate((int) config('chat.list_page_size'));

        return response()->json([
            'conversations' => $page->getCollection()->map(fn (Conversation $conversation) => $this->presenter->conversation($conversation, $request->user()))->values(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    /** A new conversation, opened by Lumi's fixed introduction. */
    public function store(Request $request, ConversationService $conversations, ThreadReader $reader): JsonResponse
    {
        $conversation = $conversations->startAssistantConversation($request->user());
        $page = $reader->page($conversation);

        return response()->json($this->presenter->thread($conversation, $request->user(), $page['messages'], $page['has_more_before']), 201);
    }

    /** A draft for the studio thread from the customer's own questions; written without the AI and edited before sending. */
    public function handoffDraft(Request $request, Conversation $conversation, MessageService $messages): JsonResponse
    {
        Gate::authorize('view', $conversation);
        abort_if($conversation->isStudio(), 404);

        return response()->json(['draft' => $messages->handoffDraft($conversation), 'from_assistant_conversation' => $conversation->id]);
    }
}
