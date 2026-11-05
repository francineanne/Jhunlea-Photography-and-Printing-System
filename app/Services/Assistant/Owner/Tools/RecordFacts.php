<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Services\Assistant\Owner\ToolResult;
use App\Support\Money;

/**
 * The fields of one order or booking an owner tool may show (Phase 7E-3a): reference, customer NAME, service or
 * package (from the snapshot), status, prices, payment status and dates. Never notes, reasons, files, phone or email.
 */
final class RecordFacts
{
    /** @return array<string, mixed> */
    public static function order(PrintingOrder $order, ToolResult $result): array
    {
        return array_filter([
            'reference' => $order->reference_number,
            'customer' => $order->customer?->name,
            'service' => $order->snapshot['service']['name'] ?? 'Printing service',
            'quantity' => $order->quantity === null ? null : $result->count((int) $order->quantity),
            'status' => $order->status->label(),
            'estimated_price' => $order->estimated_price === null ? null : $result->money(Money::toCents($order->estimated_price)),
            'confirmed_price' => $order->confirmed_price === null ? 'not set yet' : $result->money(Money::toCents($order->confirmed_price)),
            'payment' => $order->paymentStatus()->label(),
            'waiting_for_customer_info' => $order->info_request !== null ? 'yes' : null,
            'billed_with_booking' => $order->photography_booking_id !== null ? 'yes' : null,
            'placed' => $result->moment($order->created_at),
            'last_update' => $result->moment($order->updated_at),
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function booking(PhotographyBooking $booking, ToolResult $result): array
    {
        return array_filter([
            'reference' => $booking->reference_number,
            'customer' => $booking->customer?->name,
            'package' => $booking->snapshot['package']['name'] ?? 'Photography package',
            'status' => $booking->status->label(),
            'session' => $result->moment($booking->starts_at),
            'requested_time' => $booking->requested_starts_at === null ? null : $result->moment($booking->requested_starts_at),
            'proposed_time' => $booking->proposed_starts_at === null ? null : $result->moment($booking->proposed_starts_at),
            'group_size' => $booking->group_size === null ? null : $result->count((int) $booking->group_size),
            'estimated_price' => $booking->estimated_price === null ? null : $result->money(Money::toCents($booking->estimated_price)),
            'confirmed_price' => $booking->confirmed_price === null ? 'not set yet' : $result->money(Money::toCents($booking->confirmed_price)),
            'payment' => $booking->paymentStatus()->label(),
            'wants_prints' => $booking->wants_prints ? 'yes' : null,
            'requested_on' => $result->moment($booking->created_at),
        ], fn ($value) => $value !== null);
    }
}
