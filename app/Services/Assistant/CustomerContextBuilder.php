<?php

namespace App\Services\Assistant;

use App\Enums\AssistantCardType;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PrintingOrderStatus;
use App\Enums\UserRole;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * The signed-in customer's own orders and bookings for the prompt (Phase 7E-1): up to 5 still open, then the 3 most
 * recently finished. The customer is ONLY the AssistantActor's user id, and only for Ask Lumi and the studio thread:
 * guests, the owner's assist and any non-customer get CustomerRecords::none().
 *
 * Each record says only what the customer's own My orders and My bookings pages say: the reference, the service or
 * package name, the status label, the session time, the confirmed price (or "Awaiting price"), the recorded payment,
 * whether the customer can cancel it online right now (the existing policies decide) and when it last changed. Never
 * notes, reasons, the shop's questions, owner notes, contact details, files or another customer's anything.
 *
 * A fixed number of queries whatever the customer has (the user, two per kind, and their payments), and a hard
 * character cap: the oldest finished record is dropped first, then the oldest open one.
 */
class CustomerContextBuilder
{
    private const ACTIVE = 5;

    private const FINISHED = 3;

    public const SESSION_FORMAT = 'D, M j, Y g:i A';

    public function for(AssistantInput $input): CustomerRecords
    {
        if ($input->actor->userId === null || ! $input->feature->showsCards()) {
            return CustomerRecords::none();
        }

        $customer = User::query()->find($input->actor->userId);

        if ($customer === null || $customer->role !== UserRole::Customer) {
            return CustomerRecords::none();
        }

        return $this->build($customer);
    }

    public function build(User $customer): CustomerRecords
    {
        $orderFinal = [PrintingOrderStatus::Rejected, PrintingOrderStatus::Completed, PrintingOrderStatus::Cancelled];
        $bookingFinal = [BookingStatus::Rejected, BookingStatus::Completed, BookingStatus::Cancelled];

        $orders = fn () => $this->query(PrintingOrder::query(), $customer);
        $bookings = fn () => $this->query(PhotographyBooking::query(), $customer);

        $active = collect([
            ...$orders()->whereNotIn('status', $orderFinal)->latest('id')->limit(self::ACTIVE)->get(),
            ...$bookings()->whereNotIn('status', $bookingFinal)->latest('id')->limit(self::ACTIVE)->get(),
        ])->sortByDesc(fn ($record) => $record->created_at->getTimestamp() * 10 + ($record instanceof PhotographyBooking ? 1 : 0))->take(self::ACTIVE);

        $finished = collect([
            ...$orders()->whereIn('status', $orderFinal)->latest('updated_at')->latest('id')->limit(self::FINISHED)->get(),
            ...$bookings()->whereIn('status', $bookingFinal)->latest('updated_at')->latest('id')->limit(self::FINISHED)->get(),
        ])->sortByDesc(fn ($record) => $record->updated_at->getTimestamp())->take(self::FINISHED);

        $records = [...$active->map(fn ($record) => $this->describe($record, $customer))->values(), ...$finished->map(fn ($record) => $this->describe($record, $customer))->values()];

        return CustomerRecords::forCustomer($customer->id, ...$this->capped($records));
    }

    /**
     * One record as the customer sees it. Without a customer (a card drawn later) "can cancel" is simply false: cards
     * never offer it.
     */
    public function describe(PrintingOrder|PhotographyBooking $record, ?User $customer = null): CustomerRecord
    {
        $order = $record instanceof PrintingOrder;
        $payment = $record->paymentStatus();
        $paid = $record->recordedPayment?->amount;
        $where = $order ? 'at the shop' : 'at the studio on the day of the shoot';

        [$long, $short] = match (true) {
            $payment === PaymentStatus::Paid => ['Paid in full, '.Money::format((string) $paid).' recorded by the shop; nothing left to pay', 'Paid'],
            ! $record->showsPayment() => ['Nothing to pay', 'Nothing to pay'],
            $payment === PaymentStatus::AwaitingPrice => ['Awaiting price; nothing to pay until the shop confirms the price', 'Awaiting price'],
            $payment === PaymentStatus::NoCharge => ['No charge; nothing to pay', 'No charge'],
            default => ['Unpaid; '.Money::format((string) $record->confirmed_price)." to pay in full {$where}, in one payment (cash or GCash)", Money::format((string) $record->confirmed_price).' to pay'],
        };

        $price = $record->confirmed_price !== null
            ? Money::format($record->confirmed_price).' (confirmed)'
            : 'Awaiting price'.($record->estimated_price !== null ? ' (estimate '.Money::format($record->estimated_price).', not final)' : '');

        return new CustomerRecord(
            type: $order ? AssistantCardType::Order : AssistantCardType::Booking,
            id: $record->id,
            reference: $record->reference_number,
            name: $order ? $record->serviceName() : (string) ($record->snapshot['package']['name'] ?? 'Photoshoot'),
            statusLabel: $record->status->label(),
            finished: $record->status->isFinal(),
            schedule: $order ? null : $this->schedule($record),
            price: $price,
            payment: $long,
            paymentShort: $short,
            cancellable: $customer !== null && Gate::forUser($customer)->allows('cancel', $record),
            needsInfo: $order && ! $record->status->isFinal() && $record->needsInfo(),
            lastUpdate: LocalTime::format($record->status_histories_max_created_at ?? $record->updated_at),
            states: $this->states($record, $payment),
            when: $order ? null : self::relative($this->moment($record)),
            timeNote: $order ? null : $this->timeNote($record),
        );
    }

    /**
     * @template T of PrintingOrder|PhotographyBooking
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    private function query(Builder $query, User $customer): Builder
    {
        return $query->where('customer_id', $customer->id)->with('recordedPayment')->withMax('statusHistories', 'created_at');
    }

    /** The session time with its weekday, as the booking page shows it ("Mon, Nov 9, 2026" and "10:00 AM"). */
    private function schedule(PhotographyBooking $booking): string
    {
        $at = fn ($value) => LocalTime::format($value, self::SESSION_FORMAT);

        return match ($booking->status) {
            BookingStatus::Requested => 'Requested time (not confirmed yet): '.$at($booking->starts_at),
            BookingStatus::Proposed => 'The studio proposed a new time: '.$at($booking->proposed_starts_at ?? $booking->starts_at).' (the customer has not accepted it yet; requested: '.$at($booking->starts_at).')',
            BookingStatus::Confirmed => 'Confirmed session: '.$at($booking->starts_at),
            BookingStatus::Completed => 'Session held: '.$at($booking->starts_at),
            default => 'Session time asked for: '.$at($booking->starts_at),
        };
    }

    /** The time the schedule line is about: the studio's proposal while one is open, else the session time. */
    private function moment(PhotographyBooking $booking): Carbon
    {
        return $booking->status === BookingStatus::Proposed && $booking->proposed_starts_at !== null ? $booking->proposed_starts_at : $booking->starts_at;
    }

    /**
     * How far a moment is from now in words, by calendar days in Manila (never by UTC dates): "today, in 3 hours",
     * "earlier today", "tomorrow", "in 3 days", "yesterday", "2 days ago". The model is never asked to work this out.
     */
    public static function relative(Carbon $at, ?Carbon $now = null): string
    {
        $zone = config('app.display_timezone');
        $now = ($now ?? Carbon::now())->copy()->setTimezone($zone);
        $local = $at->copy()->setTimezone($zone);
        $days = (int) $now->copy()->startOfDay()->diffInDays($local->copy()->startOfDay(), false);

        return match (true) {
            $days === 0 && $local->lessThanOrEqualTo($now) => 'earlier today (already passed)',
            $days === 0 => 'later today',
            $days === 1 => 'tomorrow',
            $days === -1 => 'yesterday (already passed)',
            $days > 1 => "in {$days} days",
            default => abs($days).' days ago (already passed)',
        };
    }

    /** The fixed note for a session time that has passed while the booking still waits for something. */
    private function timeNote(PhotographyBooking $booking): ?string
    {
        if ($this->moment($booking)->greaterThan(Carbon::now())) {
            return null;
        }

        return match ($booking->status) {
            BookingStatus::Requested => 'The requested time has already passed and the studio has not confirmed it; the customer should message the studio to arrange a new time',
            BookingStatus::Proposed => 'The proposed time has already passed and the booking is not confirmed; the customer should message the studio to arrange a new time',
            BookingStatus::Confirmed => 'The session time has passed and the booking is not marked completed yet',
            default => null,
        };
    }

    /** @return list<string> */
    private function states(PrintingOrder|PhotographyBooking $record, PaymentStatus $payment): array
    {
        $status = $record->status;

        return array_keys(array_filter([
            'confirmed' => $record instanceof PrintingOrder
                ? in_array($status, [PrintingOrderStatus::Accepted, PrintingOrderStatus::Processing, PrintingOrderStatus::ReadyForPickup, PrintingOrderStatus::Completed], true)
                : in_array($status, [BookingStatus::Confirmed, BookingStatus::Completed], true),
            'ready' => $status === PrintingOrderStatus::ReadyForPickup,
            'completed' => in_array($status, [PrintingOrderStatus::Completed, BookingStatus::Completed], true),
            'paid' => $payment === PaymentStatus::Paid,
        ]));
    }

    /**
     * The block and the records it holds, within config assistant.customer_context_max_chars.
     *
     * @param  list<CustomerRecord>  $records
     * @return array{0: list<CustomerRecord>, 1: string}
     */
    private function capped(array $records): array
    {
        $max = max(400, (int) config('assistant.customer_context_max_chars'));

        while (true) {
            $block = $this->block($records);

            if (mb_strlen($block) <= $max || $records === []) {
                return [$records, mb_substr($block, 0, $max)];
            }

            // The oldest finished record goes first (the last in the list), then the oldest open one
            $finished = array_keys(array_filter($records, fn (CustomerRecord $record) => $record->finished));
            unset($records[$finished === [] ? array_key_last($records) : end($finished)]);
            $records = array_values($records);
        }
    }

    /** @param list<CustomerRecord> $records */
    private function block(array $records): string
    {
        $heading = CustomerRecords::HEADING.' (from the shop\'s system, as on the customer\'s My orders and My bookings pages; times are Philippine time)';

        if ($records === []) {
            return $heading."\nNone: this customer has no printing orders or photoshoot bookings yet.";
        }

        return $heading."\n".implode("\n", array_map(fn (CustomerRecord $record) => $record->line(), $records));
    }
}
