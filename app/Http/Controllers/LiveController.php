<?php

namespace App\Http\Controllers;

use App\Services\LiveStamp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 10D: the one cheap endpoint each signed-in page polls (/my/live for a customer, /owner/live for the owner; the
 * routes carry the role). It answers a version stamp of what THIS person can see, the unread count and the newest
 * unread notification's sentence. Read only; never cached by the browser.
 */
class LiveController extends Controller
{
    public function __invoke(Request $request, LiveStamp $stamp): JsonResponse
    {
        return response()->json($stamp->for($request->user()))->header('Cache-Control', 'no-store, private');
    }
}
