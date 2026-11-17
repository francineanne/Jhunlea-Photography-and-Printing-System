<?php

namespace App\Support;

use App\Models\BookingSetting;

/**
 * Phase 10D: about fifteen ready-made questions about USING the system, for the owner's FAQ editor ("Add suggested
 * questions"). They are worded with the shop's CURRENT settings (downpayment percent and deadline, the pay-first
 * amount, the request hold, the cancel cutoff, the unclaimed days), and are always added UNPUBLISHED: the owner reads,
 * edits and publishes each one. The same text, with the starting defaults, is in docs/FAQ_DRAFTS.md.
 */
class FaqDrafts
{
    public function __construct(private readonly ShopSettings $settings) {}

    /** @return list<array{question: string, answer: string}> */
    public function all(): array
    {
        $percent = $this->settings->downpaymentPercent();
        $days = $this->settings->paymentRule('downpayment_due_days');
        $hours = $this->settings->paymentRule('downpayment_min_hours');
        $unclaimed = $this->settings->paymentRule('unclaimed_after_days');
        $prepay = Money::format($this->settings->prepayFrom());
        $hold = (int) BookingSetting::current()->request_hold_hours;
        $cutoff = (int) config('booking.customer_cancel_cutoff_hours');
        $gcash = trim(($this->settings->gcashName() ?? '').' '.($this->settings->gcashNumber() ?? ''));
        $plural = fn (int $n, string $word) => $n.' '.$word.($n === 1 ? '' : 's');

        $downpayment = $percent > 0
            ? "Yes. Once the studio confirms your photoshoot, a downpayment of {$percent}% of the confirmed price (rounded up to the whole peso) keeps your time. It is due {$plural($days, 'day')} before the shoot, or at least {$plural($hours, 'hour')} after the studio confirms it when the shoot is sooner. Your booking page shows the exact amount and deadline."
            : 'No. A photoshoot has no downpayment: you pay the full confirmed price at the studio on the day of your shoot.';

        return [
            ['question' => 'Do I need an account to order or book?', 'answer' => 'You can browse the services and packages without an account. To send a printing order or a booking request online, create a free account with your name, email address and Philippine mobile number. You can also simply visit the shop: walk-in customers do not need an account.'],
            ['question' => 'Why do I have to verify my email?', 'answer' => 'After you register we send a link to your email address. Open it within 60 minutes to verify your email; after that you can order and book. If it did not arrive, check your spam folder or press "Send the link again" on the Check your email page. Typed the wrong email? Change it on your account page.'],
            ['question' => 'I forgot my password. What do I do?', 'answer' => 'On the login page choose "Forgot password" and type your email. We send a 6-digit code that works for 10 minutes. Type the code together with your new password. For your safety, other devices signed in to your account are signed out.'],
            ['question' => 'How do I send a printing order?', 'answer' => 'Choose "Request printing", pick the service and its options, upload your files and send the order. Sending an order does not mean it is accepted yet: the shop checks your files first and confirms the final price. You can follow every step on your order page with its PR- reference number.'],
            ['question' => 'When do I pay for a printing order?', 'answer' => "An order with a confirmed price of {$prepay} or more is paid in full before printing starts. A smaller order is paid in full when you pick it up. Orders are released only when fully paid."],
            ['question' => 'How do I book a photoshoot?', 'answer' => "Choose \"Book a photoshoot\", pick a package, a date and a free time, and send the request. Times that are already requested or booked are not shown. A request is not a confirmed booking: the studio confirms it, offers another time, or tells you if it cannot take it. While you wait, your time is held for {$plural($hold, 'hour')}."],
            ['question' => 'Do I need to pay a downpayment for a photoshoot?', 'answer' => $downpayment],
            ['question' => 'What happens if I do not pay the downpayment on time?', 'answer' => $percent > 0
                ? 'A booking whose downpayment is not paid by its deadline is cancelled by the system and the time becomes free for other customers. If you need more time, message the studio before the deadline.'
                : 'There is no downpayment, so nothing is due before your shoot day.'],
            ['question' => 'When do I pay the balance?', 'answer' => $percent > 0
                ? 'The balance (the confirmed price minus your downpayment) is paid at the studio on the day of your photoshoot. Photos are released once the booking is fully paid.'
                : 'The full confirmed price is paid at the studio on the day of your photoshoot. Photos are released once the booking is fully paid.'],
            ['question' => 'Is the downpayment refundable?', 'answer' => $percent > 0
                ? 'No. A recorded downpayment is not refunded when you cancel. If the studio itself has to cancel, the studio decides with you whether the payment is kept or refunded.'
                : 'There is no downpayment to refund. If you paid and the studio has to cancel, the studio decides with you whether the payment is kept or refunded.'],
            ['question' => 'How do I cancel an order or a booking?', 'answer' => "Open the order or the booking and choose Cancel. A printing order can be cancelled while nothing is paid and printing has not started. A confirmed photoshoot can be cancelled online until {$plural($cutoff, 'hour')} before it starts; after that, please message the studio."],
            ['question' => 'How do I pay?', 'answer' => 'There is no online payment. Pay at the shop in cash or by GCash'.($gcash !== '' ? ", or send GCash to {$gcash}" : ', or send GCash to the shop').' and then send the GCash reference number in Messages. The studio records your payment after checking it, and your order or booking page shows it.'],
            ['question' => 'Can I just walk in?', 'answer' => 'Yes. Walk-in customers are welcome for printing and for photoshoots when a time is free. The shop records your order or booking at the counter and you pay there. You do not need an account.'],
            ['question' => 'What happens if I do not pick up my prints?', 'answer' => "An unpaid order that is not picked up {$plural($unclaimed, 'day')} after it is ready may be closed as Unclaimed. After that, your next orders are paid before printing."],
            ['question' => 'Who answers when I chat?', 'answer' => 'The Chat button has two parts. "Ask Lumi" is an AI assistant that answers questions about our services, packages and rules; its answers are suggestions, not bookings or quotations. "Message the studio" reaches a person at the shop. When the studio is away, Lumi may answer first and the studio replies when it is back.'],
            ['question' => 'What do you do with my personal data?', 'answer' => 'We keep only what is needed to serve you: your account details, your requests and files, recorded payments and your messages. We do not sell it or use it for advertising. You can correct your details on your account page and ask the studio to delete your account. The Terms and privacy page has the full details (Data Privacy Act of 2012, RA 10173).'],
        ];
    }
}
