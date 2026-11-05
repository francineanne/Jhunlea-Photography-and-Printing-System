<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\OwnerAskRequest;
use App\Models\OwnerAssistantMessage;
use App\Services\Assistant\Owner\OwnerAskHistory;
use App\Services\Assistant\Owner\OwnerAskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The owner's Ask Lumi endpoints (Phase 7E-3a, JSON only; the page comes in 7E-3b). Owner role only (route group);
 * every answer is read-only. The briefing is written by code with no provider call.
 */
class OwnerAskController extends Controller
{
    public function __construct(
        private readonly OwnerAskService $assistant,
        private readonly OwnerAskHistory $history,
    ) {}

    public function ask(OwnerAskRequest $request): JsonResponse
    {
        $owner = $request->user();
        $result = $this->assistant->ask($owner, $request->message(), $request->fresh() ? [] : $this->history->recent($owner));
        $this->history->append($owner, $request->message(), $result);

        return response()->json($result->toArray());
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json(['messages' => $this->history->page($request->user())->map(fn (OwnerAssistantMessage $row) => [
            'role' => $row->role,
            'text' => $row->text,
            'cards' => $row->meta['cards'] ?? [],
            'links' => $row->meta['links'] ?? [],
            'via_backup' => (bool) ($row->meta['via_backup'] ?? false),
            'created_at' => $row->created_at?->toIso8601ZuluString(),
        ])->all()]);
    }

    public function briefing(Request $request): JsonResponse
    {
        return response()->json($this->assistant->briefing($request->user())->toArray());
    }
}
