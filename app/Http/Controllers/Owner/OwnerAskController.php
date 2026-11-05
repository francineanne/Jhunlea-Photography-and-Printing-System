<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\OwnerAskRequest;
use App\Models\OwnerAssistantMessage;
use App\Services\Assistant\Owner\OwnerAskHistory;
use App\Services\Assistant\Owner\OwnerAskService;
use App\Support\LocalTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The owner's Ask Lumi (Phase 7E-3a endpoints, 7E-3b page). Owner role only (route group); every answer is read-only.
 * The briefing is written by code with no provider call. The history is paged with a cursor (?before=id).
 */
class OwnerAskController extends Controller
{
    public function __construct(
        private readonly OwnerAskService $assistant,
        private readonly OwnerAskHistory $history,
    ) {}

    /** The full page; the latest turns are drawn by the server too, so they show without JavaScript. */
    public function index(Request $request): View
    {
        return view('owner.assistant.index', ['history' => $this->history->page($request->user())['messages']]);
    }

    public function ask(OwnerAskRequest $request): JsonResponse
    {
        $owner = $request->user();
        $fresh = $request->fresh();
        $result = $this->assistant->ask($owner, $request->message(), $fresh ? [] : $this->history->recent($owner), $fresh ? [] : $this->history->verified($owner));
        $this->history->append($owner, $request->message(), $result);

        return response()->json($result->toArray());
    }

    public function history(Request $request): JsonResponse
    {
        $before = filter_var($request->query('before'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $page = $this->history->page($request->user(), $before === false ? null : $before);

        return response()->json([
            'messages' => $page['messages']->map(fn (OwnerAssistantMessage $row) => [
                'id' => $row->id,
                'role' => $row->role,
                'text' => $row->text,
                'cards' => $row->meta['cards'] ?? [],
                'links' => $row->meta['links'] ?? [],
                'via_backup' => (bool) ($row->meta['via_backup'] ?? false),
                'created_at' => $row->created_at?->toIso8601ZuluString(),
                'local' => $row->created_at === null ? null : LocalTime::format($row->created_at),
            ])->all(),
            'older' => $page['older'],
        ]);
    }

    public function briefing(Request $request): JsonResponse
    {
        // items: the same lines as the briefing, each with the owner page it is about (7E-3b, the overview card)
        return response()->json($this->assistant->briefingJson($request->user()));
    }
}
