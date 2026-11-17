<?php

namespace App\Http\Controllers\Owner;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Chat\ConversationService;
use App\Services\Chat\OwnerPresence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Phase 10E: the owner starts a conversation. "New message" on the Messages page searches the customers who HAVE an
 * account (walk-ins have none; unverified accounts are shown with a note), and choosing one opens that customer's one
 * Studio thread, created when it does not exist yet (ConversationService::studioFor(), race-safe since 7B). The same
 * start is the "Message" button of a customer page and "Message customer about this" on an order or booking page,
 * which also attaches that record as the chip of the owner's next message. Starting counts as the owner being active,
 * so Lumi stays quiet.
 */
class StartConversationController extends Controller
{
    private const RESULTS = 20;

    public function create(Request $request): View
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:80']]);
        $search = trim((string) ($validated['q'] ?? ''));

        $customers = $search === '' ? collect() : User::query()
            ->where('role', UserRole::Customer)->where('is_walk_in', false)
            ->where(function ($query) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $digits = preg_replace('/\D/', '', $search);
                $query->where('name', 'like', $like)->orWhere('email', 'like', $like)
                    ->when(strlen((string) $digits) >= 4, fn ($inner) => $inner->orWhere('phone', 'like', '%'.$digits.'%'));
            })
            ->orderBy('name')->limit(self::RESULTS)
            ->get(['id', 'name', 'email', 'phone', 'email_verified_at', 'avatar_path', 'is_walk_in']);

        return view('owner.messages.new', ['search' => $search, 'customers' => $customers, 'limit' => self::RESULTS]);
    }

    public function store(Request $request, ConversationService $conversations, OwnerPresence $presence): RedirectResponse
    {
        $validated = $request->validate([
            'customer' => ['required', 'integer'],
            'about_type' => ['nullable', 'string', 'in:printing_order,photography_booking'],
            'about_id' => ['nullable', 'integer', 'required_with:about_type'],
        ]);

        $customer = User::query()->where('role', UserRole::Customer)->find($validated['customer']);

        if ($customer === null) {
            abort(404);
        }

        if ($customer->isWalkIn()) {
            return back()->with('error', 'Walk-in customers have no account to message.'.(filled($customer->phone) ? ' Call '.$customer->phone.'.' : ''));
        }

        $presence->touch();
        $conversation = $conversations->studioFor($customer);

        // The record to talk about must be one of THIS customer's own orders or bookings
        $about = null;
        if (! empty($validated['about_type'])) {
            $model = $validated['about_type'] === 'printing_order' ? PrintingOrder::class : PhotographyBooking::class;
            abort_unless($model::query()->whereKey($validated['about_id'])->where('customer_id', $customer->id)->exists(), 404);
            $about = $validated['about_type'].':'.$validated['about_id'];
        }

        return redirect()->route('owner.messages.show', array_filter([$conversation, 'about' => $about]));
    }
}
