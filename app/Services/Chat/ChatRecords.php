<?php

namespace App\Services\Chat;

use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\Money;

/**
 * Phase 10E: a customer's orders and bookings as the chat shows them (like an order card in a shop chat): the owner's
 * side panel and "attach" list, and the card of a message that is about one. Read only; only the given customer's own
 * records; never a word of payment logic of its own (the amount due is the record's own amountDue()).
 */
class ChatRecords
{
    private const EACH = 8;

    /** @return list<array<string, mixed>> active ones first, newest first, then the finished ones */
    public function for(User $customer, bool $owner): array
    {
        $orders = PrintingOrder::query()->where('customer_id', $customer->id)->with('payments')->latest('id')->limit(self::EACH)->get();
        $bookings = PhotographyBooking::query()->where('customer_id', $customer->id)->with('payments')->latest('id')->limit(self::EACH)->get();

        return collect([...$orders, ...$bookings])
            ->sortByDesc(fn ($record) => [! $record->status->isFinal(), $record->created_at->getTimestamp()])
            ->map(fn ($record) => [
                'type' => $record instanceof PrintingOrder ? 'printing_order' : 'photography_booking',
                'id' => $record->id,
                'active' => ! $record->status->isFinal(),
                'url' => $record instanceof PrintingOrder
                    ? route($owner ? 'owner.orders.show' : 'customer.printing.show', $record)
                    : route($owner ? 'owner.bookings.show' : 'customer.bookings.show', $record),
            ] + self::card($record))
            ->values()->all();
    }

    /** @return array{reference: string, title: string, status: string, when: ?string, due: ?string} */
    public static function card(PrintingOrder|PhotographyBooking $record): array
    {
        $due = $record->amountDue();

        return [
            'reference' => (string) $record->reference_number,
            'title' => $record instanceof PrintingOrder ? $record->serviceName() : (string) ($record->snapshot['package']['name'] ?? $record->package?->name ?? 'Photoshoot'),
            'status' => $record instanceof PhotographyBooking ? $record->statusLabel() : $record->status->label(),
            'when' => $record instanceof PhotographyBooking ? LocalTime::format($record->starts_at, 'M j, g:i A') : null,
            'due' => $due !== null && $due !== '' && (float) $due > 0 ? Money::format($due).' due' : null,
        ];
    }
}
