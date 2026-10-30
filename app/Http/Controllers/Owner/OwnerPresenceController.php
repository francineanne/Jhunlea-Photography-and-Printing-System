<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\PresenceRequest;
use App\Services\Chat\ChatPresenter;
use App\Services\Chat\OwnerPresence;
use Illuminate\Http\JsonResponse;

/** The owner's presence in the studio chat (Phase 7B): the chosen mode and whether the owner counts as away now. */
class OwnerPresenceController extends Controller
{
    public function __construct(private readonly OwnerPresence $presence) {}

    public function show(ChatPresenter $presenter): JsonResponse
    {
        return response()->json([
            'mode' => $this->presence->mode()->value,
            'away' => $this->presence->isAway(),
            'last_active_at' => $presenter->time($this->presence->lastActiveAt()),
        ]);
    }

    public function update(PresenceRequest $request, ChatPresenter $presenter): JsonResponse
    {
        $this->presence->setMode($request->mode());

        return $this->show($presenter);
    }
}
