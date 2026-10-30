<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The full-size chat page (Phase 7C). ?tab= picks the first tab. ?about=printing_order:ID or photography_booking:ID shows
 * the "About PR-..." chip only when the record is the customer's own; anything else is ignored, and the send checks it
 * again on the server.
 */
class AssistantPageController extends Controller
{
    private const ABOUT = ['printing_order' => PrintingOrder::class, 'photography_booking' => PhotographyBooking::class];

    public function __invoke(Request $request): View
    {
        return view('customer.assistant.page', [
            'tab' => $request->query('tab') === 'studio' ? 'studio' : 'ask',
            'about' => $this->about($request->user(), $request->query('about')),
        ]);
    }

    /** @return array{type: string, id: int, reference: string}|null */
    private function about(User $customer, mixed $value): ?array
    {
        if (! is_string($value) || ! preg_match('/^(printing_order|photography_booking):(\d{1,18})$/', $value, $match)) {
            return null;
        }

        $reference = self::ABOUT[$match[1]]::query()->whereKey((int) $match[2])->where('customer_id', $customer->id)->value('reference_number');

        return $reference === null ? null : ['type' => $match[1], 'id' => (int) $match[2], 'reference' => (string) $reference];
    }
}
