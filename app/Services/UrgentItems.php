<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Enums\UserRole;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\PaymentRules;
use App\Support\PhilippineMobile;
use App\Support\ShopSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 10O: the "Needs your attention" list shown once after a person signs in. Read only; words only; no rule.
 * Each item: one plain sentence, its deadline (Manila time when shown), the link to the record, and the list it
 * belongs to (for "+N more"). Most urgent first; at most MAX shown.
 *
 * A customer's list reads ONLY their own records:
 * - a downpayment due within 48 hours, a time the studio offered that waits for their answer,
 * - an Accepted order that must be paid before printing, an order Ready for pickup and not paid.
 * The owner's list:
 * - requests not answered whose hold ends within 12 hours or already ran out (those first),
 * - downpayments due today or tomorrow (a walk-in's phone to call), shoots today or tomorrow with something still due,
 * - orders ready for pickup, unpaid, that may now be closed as Unclaimed (the 10K rule).
 * Walk-ins never sign in, so they never get one. A fixed number of queries (one per kind, relations eager loaded).
 */
class UrgentItems
{
    public const MAX = 5;

    public const SESSION_KEY = 'urgent-shown-for';

    public function __construct(private readonly ShopSettings $settings, private readonly PaymentRules $rules) {}

    /**
     * The list for this person on the FIRST page they open after signing in, or null (already shown in this session,
     * a walk-in, or nothing urgent). The session flag is set on that first look either way, so later pages never ask.
     *
     * @return array{items: list<array{text: string, when: ?Carbon, url: string, list: string, phone: ?array{href: string, text: string}}>, more: int, moreUrl: ?string}|null
     */
    public function onceForLogin(?User $user): ?array
    {
        if (! config('notifications.urgent_on_login') || $user === null || $user->isWalkIn() || session(self::SESSION_KEY) === $user->id) {
            return null;
        }

        session([self::SESSION_KEY => $user->id]);
        $all = $this->for($user);

        if ($all->isEmpty()) {
            return null;
        }

        $shown = $all->take(self::MAX)->values();
        $hidden = $all->slice(self::MAX)->values();

        return [
            'items' => $shown->all(),
            'more' => $hidden->count(),
            'moreUrl' => $hidden->first()['list'] ?? null,
        ];
    }

    /** @return Collection<int, array{text: string, when: ?Carbon, url: string, list: string, phone: ?array{href: string, text: string}, rank: int}> */
    public function for(User $user): Collection
    {
        $items = $user->role === UserRole::Owner ? $this->owner() : $this->customer($user);

        return $items->sortBy('rank')->values()->map(fn (array $item) => collect($item)->except('rank')->all());
    }

    /** @return Collection<int, array<string, mixed>> */
    private function customer(User $customer): Collection
    {
        $now = Carbon::now('UTC');
        $items = collect();

        $bookings = PhotographyBooking::query()->where('customer_id', $customer->id)
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('status', BookingStatus::AwaitingDownpayment)->whereBetween('downpayment_due_at', [$now, $now->copy()->addHours(48)]))
                ->orWhere(fn ($q) => $q->where('status', BookingStatus::Proposed)->waiting()))
            ->get();

        foreach ($bookings as $booking) {
            $items->push($booking->status === BookingStatus::AwaitingDownpayment ? [
                'text' => 'Pay the downpayment of '.Money::cents($this->rules->downpaymentCents((string) $booking->confirmed_price)).' for '.$booking->reference_number.', or the booking is cancelled.',
                'when' => $booking->downpayment_due_at,
                'url' => route('customer.bookings.show', $booking).'#payment',
                'list' => route('customer.bookings.index'),
            ] : [
                'text' => 'The studio offered a new time for '.$booking->reference_number.'. Accept it or ask for another time, or the offer expires.',
                'when' => $booking->proposed_starts_at,
                'url' => route('customer.bookings.show', $booking),
                'list' => route('customer.bookings.index'),
            ]);
        }

        $orders = PrintingOrder::query()->where('customer_id', $customer->id)
            ->whereIn('status', [PrintingOrderStatus::Accepted, PrintingOrderStatus::ReadyForPickup])
            ->with(['activePayments', 'statusHistories' => fn ($query) => $query->where('to_status', PrintingOrderStatus::ReadyForPickup)->reorder('id', 'desc')])
            ->get();

        foreach ($orders as $order) {
            $due = $this->due($order);

            if ($due === null) {
                continue;
            }

            if ($order->status === PrintingOrderStatus::Accepted) {
                if ($order->requires_prepayment) {
                    $items->push(['text' => 'Pay '.$due.' for '.$order->reference_number.' so the shop can start printing.', 'when' => null,
                        'url' => route('customer.printing.show', $order).'#payment', 'list' => route('customer.printing.index')]);
                }

                continue;
            }

            $items->push(['text' => $order->reference_number.' is ready. Pick it up and pay '.$due.', or the shop may close it as Unclaimed.',
                'when' => $this->unclaimableFrom($order), 'url' => route('customer.printing.show', $order), 'list' => route('customer.printing.index')]);
        }

        return $items->map(fn (array $item) => $item + ['phone' => null, 'rank' => $this->rank($item['when'])]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function owner(): Collection
    {
        $now = Carbon::now('UTC');
        $endOfTomorrow = LocalTime::in($now)->addDay()->endOfDay()->utc();
        $items = collect();
        $who = fn ($record) => ($record->customer?->name ?? 'Deleted account').($record->isWalkIn() ? ', Walk-in' : '');

        // Requests not answered: those that ran out of their hold, then those whose hold ends within 12 hours
        $requests = PhotographyBooking::query()->where('status', BookingStatus::Requested)->waiting()
            ->where(fn ($query) => $query->whereNull('hold_expires_at')->orWhere('hold_expires_at', '<=', $now->copy()->addHours(12)))
            ->with('customer:id,name,is_walk_in')->orderBy('starts_at')->limit(50)->get();

        foreach ($requests as $booking) {
            $lost = $booking->hold_expires_at === null || $booking->hold_expires_at->lte($now);
            $items->push([
                'text' => $booking->reference_number.' ('.$who($booking).') is waiting for your answer'
                    .($lost ? ' and no longer holds its time.' : '; its time is held until '.LocalTime::format($booking->hold_expires_at, 'g:i A').'.'),
                'when' => $lost ? $booking->starts_at : $booking->hold_expires_at,
                'url' => route('owner.bookings.show', $booking),
                'list' => route('owner.bookings.index', ['status' => BookingStatus::Requested->value]),
                'phone' => null,
                'rank' => $lost ? 0 : $this->rank($booking->hold_expires_at),
            ]);
        }

        // Downpayments due today or tomorrow
        $awaiting = PhotographyBooking::query()->where('status', BookingStatus::AwaitingDownpayment)
            ->whereBetween('downpayment_due_at', [$now, $endOfTomorrow])
            ->with('customer:id,name,phone,is_walk_in')->orderBy('downpayment_due_at')->limit(50)->get();

        foreach ($awaiting as $booking) {
            $items->push([
                'text' => $booking->reference_number.' ('.$who($booking).'): the downpayment is due. If it is not paid, the booking is cancelled.',
                'when' => $booking->downpayment_due_at,
                'url' => route('owner.bookings.show', $booking),
                'list' => route('owner.bookings.index', ['status' => BookingStatus::AwaitingDownpayment->value]),
                'phone' => $this->phone($booking),
                'rank' => $this->rank($booking->downpayment_due_at),
            ]);
        }

        // Shoots today or tomorrow with something still to pay
        $shoots = PhotographyBooking::query()->where('status', BookingStatus::Confirmed)
            ->whereBetween('starts_at', [LocalTime::in($now)->startOfDay()->utc(), $endOfTomorrow])
            ->with(['customer:id,name,phone,is_walk_in', 'activePayments'])->orderBy('starts_at')->limit(50)->get();

        foreach ($shoots as $booking) {
            if (($due = $this->due($booking)) === null) {
                continue;
            }
            $items->push([
                'text' => $booking->reference_number.' ('.$who($booking).'): the shoot is on '.LocalTime::format($booking->starts_at, 'D, M j').' with '.$due.' still to pay.',
                'when' => $booking->starts_at,
                'url' => route('owner.bookings.show', $booking).'#payment',
                'list' => route('owner.bookings.index', ['status' => BookingStatus::Confirmed->value]),
                'phone' => $this->phone($booking),
                'rank' => $this->rank($booking->starts_at),
            ]);
        }

        // Orders ready for pickup, unpaid, that may now be closed as Unclaimed (the 10K rule)
        $orders = PrintingOrder::query()->where('status', PrintingOrderStatus::ReadyForPickup)
            ->with(['customer:id,name,phone,is_walk_in', 'activePayments', 'statusHistories' => fn ($query) => $query->where('to_status', PrintingOrderStatus::ReadyForPickup)->reorder('id', 'desc')])
            ->limit(200)->get();

        foreach ($orders as $order) {
            $from = $this->unclaimableFrom($order);

            if ($this->due($order) === null || $from === null || $from->isFuture()) {
                continue;
            }
            $items->push([
                'text' => $order->reference_number.' ('.$who($order).') was not picked up or paid. You can close it as Unclaimed.',
                'when' => $from,
                'url' => route('owner.orders.show', $order),
                'list' => route('owner.orders.index', ['status' => PrintingOrderStatus::ReadyForPickup->value]),
                'phone' => $this->phone($order),
                'rank' => $this->rank($from),
            ]);
        }

        return $items;
    }

    /** What is still due, formatted, or null when nothing is (no confirmed price, settled). */
    private function due(PrintingOrder|PhotographyBooking $record): ?string
    {
        $due = $record->amountDue();

        return $due !== null && Money::toCents($due) > 0 ? Money::format($due) : null;
    }

    /** From when an unpaid order ready for pickup may be closed as Unclaimed: the day it last became ready + the setting. */
    private function unclaimableFrom(PrintingOrder $order): ?Carbon
    {
        $ready = $order->statusHistories->first();
        $days = (int) $this->settings->paymentRule('unclaimed_after_days');

        return $ready === null || $days < 1 ? null : LocalTime::in($ready->created_at)->startOfDay()->addDays($days)->utc();
    }

    /** A walk-in's number to call (they get no notifications); null for an online customer. */
    private function phone(PrintingOrder|PhotographyBooking $record): ?array
    {
        $phone = $record->isWalkIn() ? ($record instanceof PhotographyBooking ? ($record->contact_number ?: $record->customer?->phone) : $record->customer?->phone) : null;

        return filled($phone) ? ['href' => PhilippineMobile::telHref($phone), 'text' => PhilippineMobile::display($phone)] : null;
    }

    /** Sooner first; an item without a deadline last. */
    private function rank(?Carbon $when): int
    {
        return $when === null ? PHP_INT_MAX : $when->getTimestamp();
    }
}
