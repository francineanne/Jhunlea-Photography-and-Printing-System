<?php

namespace App\Http\Controllers\Owner;

use App\Enums\AssistantFeature;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\Chat\OwnerAssistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Owner assist (Phase 7D): a draft reply for the composer, or a short summary of the thread. Both are only shown: never
 * sent and never stored (the engine writes only its assistant_runs metadata row).
 */
class OwnerAssistController extends Controller
{
    public function __construct(private readonly OwnerAssistService $assist) {}

    public function suggest(Request $request, Conversation $conversation): JsonResponse
    {
        return $this->run($request, $conversation, AssistantFeature::OwnerAssistSuggest);
    }

    public function summarize(Request $request, Conversation $conversation): JsonResponse
    {
        return $this->run($request, $conversation, AssistantFeature::OwnerAssistSummarize);
    }

    private function run(Request $request, Conversation $conversation, AssistantFeature $feature): JsonResponse
    {
        Gate::authorize('send', $conversation);

        return response()->json($this->assist->run($conversation, $request->user(), $feature));
    }
}
