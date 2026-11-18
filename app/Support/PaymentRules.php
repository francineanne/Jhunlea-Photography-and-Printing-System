<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The shop's payment rules worked out in ONE place (Phase 10B), from the owner's settings (ShopSettings):
 * the downpayment of a confirmed price, its deadline, whether a printing order is paid before printing, and the
 * sentences that state the rules (the customer's "How to pay" box and Lumi read the same words).
 *
 * Money is whole centavos; times are UTC moments, shown in Manila time.
 */
class PaymentRules
{
    public function __construct(private readonly ShopSettings $settings) {}

    /**
     * The downpayment of a confirmed price: the owner's percent of it, rounded UP to the whole peso, never more than
     * the price. 0 when there is no downpayment (percent 0 or a price of 0).
     */
    public function downpaymentCents(string $confirmedPrice): int
    {
        $price = Money::toCents($confirmedPrice);
        $percent = $this->settings->downpaymentPercent();

        if ($price <= 0 || $percent <= 0) {
            return 0;
        }

        // price * percent / 100 in centavos, rounded up to a whole peso (100 centavos), in whole numbers only
        $pesos = intdiv($price * $percent + 9999, 10000);

        return min($price, $pesos * 100);
    }

    /** Whether confirming a photoshoot at this price asks for a downpayment first. */
    public function needsDownpayment(?string $confirmedPrice): bool
    {
        return $confirmedPrice !== null && $this->downpaymentCents($confirmedPrice) > 0;
    }

    /**
     * The downpayment deadline: the shoot start minus "Downpayment due days" days. When that is less than "Minimum hours
     * to pay" after the confirmation, it is the confirmation plus those hours instead; never later than the shoot start.
     */
    public function dueAt(CarbonInterface $shootStart, CarbonInterface $confirmedAt): Carbon
    {
        $start = Carbon::instance($shootStart)->utc();
        $confirmed = Carbon::instance($confirmedAt)->utc();
        $due = $start->copy()->subDays($this->settings->paymentRule('downpayment_due_days'));
        $earliest = $confirmed->copy()->addHours($this->settings->paymentRule('downpayment_min_hours'));

        if ($due->lt($earliest)) {
            $due = $earliest;
        }

        return $due->gt($start) ? $start : $due;
    }

    /** The deadline after a voided downpayment sends a Confirmed booking back: now + config hours, never past the start. */
    public function dueAfterVoid(CarbonInterface $shootStart): Carbon
    {
        $due = Carbon::now('UTC')->addHours((int) config('payments.repay_hours_after_void'));

        return $due->gt($shootStart) ? Carbon::instance($shootStart)->utc() : $due;
    }

    /** Whether a printing order accepted at this price is paid before printing (the customer flag is separate). */
    public function prepayByPrice(string $confirmedPrice): bool
    {
        return Money::toCents($confirmedPrice) >= Money::toCents($this->settings->prepayFrom());
    }

    /**
     * What may be recorded next for this order or booking, kind => amount in centavos, given its status, price and
     * active payments. Empty when nothing can be recorded. PaymentService asks the same question on the locked row.
     *
     * @param  iterable<Payment>  $active  the recorded (active) payments
     * @return array<string, int>
     */
    public function options(PrintingOrder|PhotographyBooking $record, iterable $active): array
    {
        if ($record->confirmed_price === null || $record->status->isClosed() || ! $record->status->takesPayment()) {
            return [];
        }

        $price = Money::toCents($record->confirmed_price);
        $kinds = collect($active)->map(fn (Payment $payment) => $payment->kind)->all();

        if ($price === 0) {
            return [];
        }

        if ($kinds === []) {
            $options = [];

            // A photoshoot that waits for its downpayment may also be paid in full at once
            if ($record instanceof PhotographyBooking && $record->status === BookingStatus::AwaitingDownpayment) {
                $options[PaymentKind::Downpayment->value] = $this->downpaymentCents($record->confirmed_price);
            }

            return $options + [PaymentKind::Full->value => $price];
        }

        if ($record instanceof PhotographyBooking && $kinds === [PaymentKind::Downpayment]) {
            $paid = (int) collect($active)->sum(fn (Payment $payment) => Money::toCents($payment->amount));

            return $price > $paid ? [PaymentKind::Balance->value => $price - $paid] : [];
        }

        return [];
    }

    /**
     * The rules in plain sentences, from the settings: for the customer's pages and for Lumi.
     *
     * @return list<string>
     */
    public function sentences(): array
    {
        $percent = $this->settings->downpaymentPercent();
        $days = $this->settings->paymentRule('downpayment_due_days');
        $hours = $this->settings->paymentRule('downpayment_min_hours');
        $prepay = Money::format($this->settings->prepayFrom());
        $unclaimed = $this->settings->paymentRule('unclaimed_after_days');

        $photo = $percent > 0
            ? "A photoshoot is held with a downpayment of {$percent}% of its confirmed price (rounded up to the whole peso), due {$days} ".($days === 1 ? 'day' : 'days')." before the shoot, or at least {$hours} hours after the studio confirms it if the shoot is sooner. The balance is paid on the day of the shoot. A booking whose downpayment is not paid by the deadline is cancelled. The downpayment is non-refundable when the customer cancels."
            : 'A photoshoot has no downpayment: the full confirmed price is paid on the day of the shoot.';

        return [
            'The system only records payments. The customer pays face to face at the shop (cash or GCash), or sends GCash to the shop and sends the reference number in Messages; the studio records the payment after checking it. There is no online payment.',
            $photo,
            "A printing order with a confirmed price of {$prepay} or more is paid in full before printing; a smaller order is paid in full at pickup. An unpaid order not picked up {$unclaimed} ".($unclaimed === 1 ? 'day' : 'days').' after it is ready may be closed as Unclaimed, and that customer then pays before printing next time.',
            'Orders and photos are released only when fully paid.',
        ];
    }

    /**
     * Phase 10D: the one short policy line of the public top bar and the customer footer, from the current settings.
     */
    /**
     * Phase 10G: the one fixed payment sentence for places that cannot read the settings (the payment notice, the sign-in
     * pages, the sidebar). It replaces "Payment is made face-to-face at ...", which no longer matched the 10B rules (GCash
     * can be sent from anywhere with its reference in Messages).
     */
    public const NOTICE = 'Pay at the shop in cash or GCash, or send GCash and the reference number in Messages. No online checkout.';

    public function shortLine(): string
    {
        $percent = $this->settings->downpaymentPercent();

        return $percent > 0
            ? "No online payment. Pay at the shop or by GCash; photoshoots need a {$percent}% downpayment."
            : 'No online payment. Pay at the shop in cash or by GCash.';
    }
}
