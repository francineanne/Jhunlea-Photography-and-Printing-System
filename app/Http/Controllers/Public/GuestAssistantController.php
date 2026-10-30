<?php

namespace App\Http\Controllers\Public;

use App\Enums\AssistantFeature;
use App\Enums\AssistantIntent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\GuestAssistantRequest;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\AssistantInput;
use App\Services\Chat\MessageService;
use Illuminate\Http\JsonResponse;

/**
 * Ask Lumi for a guest (Phase 7C). Nothing is stored in the chat tables: the conversation lives in the guest's page and
 * only the usual metadata row of assistant_runs is written, keyed by a hash of the IP.
 */
class GuestAssistantController extends Controller
{
    public function __invoke(GuestAssistantRequest $request, AssistantEngine $engine, MessageService $messages): JsonResponse
    {
        $result = $engine->handle(new AssistantInput(
            AssistantFeature::AskLumi,
            AssistantActor::guest((string) $request->ip()),
            $messages->normalize($request->message()),
            $request->history(),
        ));

        return response()->json([
            'reply' => $result->intent === AssistantIntent::Handoff ? $result->reply.' '.config('chat.messages.guest_handoff_signin') : $result->reply,
            'cards' => $result->cards,
            'intent' => $result->intent?->value,
            'outcome' => $result->outcome->value,
        ]);
    }
}
