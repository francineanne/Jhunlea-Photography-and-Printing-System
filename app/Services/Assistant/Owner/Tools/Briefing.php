<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Models\User;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;
use App\Services\Chat\StudioInbox;
use App\Services\DashboardService;

/**
 * Today at a glance, from the owner overview's own numbers (DashboardService::owner) and the inbox: recorded payments
 * today, awaiting payment, sessions today, orders to review, booking requests, unanswered messages. Deterministic:
 * text() writes the sentence with no model at all (the briefing endpoint uses it).
 */
class Briefing extends OwnerTool
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly StudioInbox $inbox,
    ) {}

    public function name(): string
    {
        return 'briefing';
    }

    public function description(): string
    {
        return 'Today at a glance: recorded payments today, awaiting payment, sessions today, orders to review, booking requests, unanswered messages.';
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $overview = $this->dashboard->owner($owner);
        $result = new ToolResult($this->name());

        $result->data = [
            'today' => $result->label('today').', '.$result->day($overview['today']),
            'recorded_payments_today' => $result->money($overview['recordedToday']['cents']),
            'payments_today' => $result->count($overview['recordedToday']['count']),
            'awaiting_payment' => $result->money($overview['awaiting']['cents']),
            'awaiting_payment_count' => $result->count($overview['awaiting']['count']),
            'sessions_today' => $result->count($overview['shootCount']),
            'orders_to_review' => $result->count($overview['reviewCount']),
            'booking_requests' => $result->count($overview['requestCount']),
            'unanswered_messages' => $result->count($this->inbox->unansweredCount()),
        ];

        foreach ($overview['shoots'] as $booking) {
            $result->rows[] = ['reference' => $booking->reference_number, 'customer' => $booking->customer?->name,
                'package' => $booking->snapshot['package']['name'] ?? 'Photography package', 'time' => $result->moment($booking->starts_at)];
        }

        $result->total = $overview['shootCount'];
        $result->link('Overview', route('owner.dashboard'));

        return $result;
    }

    /**
     * The briefing's lines, each with the relative owner page it is about (7E-3b). Written by code only.
     *
     * @return list<array{text: string, url: ?string}>
     */
    public static function items(ToolResult $result): array
    {
        $d = $result->data;
        $plural = fn (int $count, string $word) => $count.' '.$word.($count === 1 ? '' : 's');

        return array_map(fn (array $item) => ['text' => $item[0], 'url' => ToolResult::ownerPath(route($item[1], $item[2] ?? []))], [
            ["Recorded payments today: {$d['recorded_payments_today']} (".$plural($d['payments_today'], 'payment').')', 'owner.payments.index'],
            ["Awaiting payment: {$d['awaiting_payment']} (".$d['awaiting_payment_count'].($d['awaiting_payment_count'] === 1 ? ' order or booking' : ' orders or bookings').')', 'owner.payments.index'],
            ['Sessions today: '.$d['sessions_today'], 'owner.schedule.index', ['view' => 'day']],
            ['Printing orders to review: '.$d['orders_to_review'], 'owner.orders.index'],
            ['Booking requests to answer: '.$d['booking_requests'], 'owner.bookings.index'],
            ['Unanswered messages: '.$d['unanswered_messages'], 'owner.messages.index', ['filter' => 'unanswered']],
        ]);
    }

    /** The briefing as plain sentences, written by code only. */
    public static function text(ToolResult $result): string
    {
        $d = $result->data;
        $lines = [ucfirst($d['today']).':',
            "- Recorded payments today: {$d['recorded_payments_today']} ({$d['payments_today']} payment".($d['payments_today'] === 1 ? '' : 's').').',
            "- Awaiting payment: {$d['awaiting_payment']} ({$d['awaiting_payment_count']} order".($d['awaiting_payment_count'] === 1 ? '' : 's').' or booking'.($d['awaiting_payment_count'] === 1 ? '' : 's').').',
            "- Sessions today: {$d['sessions_today']}.",
            "- Printing orders to review: {$d['orders_to_review']}.",
            "- Booking requests to answer: {$d['booking_requests']}.",
            "- Unanswered messages: {$d['unanswered_messages']}."];

        foreach ($result->rows as $row) {
            $lines[] = "  - {$row['time']}: {$row['package']} for {$row['customer']} ({$row['reference']})";
        }

        return implode("\n", $lines);
    }
}
