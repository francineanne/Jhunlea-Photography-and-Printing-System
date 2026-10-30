<?php

namespace App\Services;

use App\Enums\BookingEvent;
use App\Enums\BookingStatus;
use App\Enums\NotificationKind;
use App\Enums\OrderEvent;
use App\Enums\PrintingOrderStatus;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The ONE place that decides who is told what (Phase 5B). The status services call it from their history-writing code,
 * so a notification exists only when a history row was written: a double click or a stale page that writes nothing
 * notifies nothing, and a refused or rolled-back action notifies nobody.
 *
 * Notifications are written after the transaction commits, in the same request (no queue). Writing one can never undo
 * or break the action that caused it: a failure is logged, without personal data, and the request carries on.
 */
class NotificationDispatcher
{
    /** @param  array{payment?: string, amount?: string}  $context */
    public function orderHistory(PrintingOrder $order, ?PrintingOrderStatus $from, PrintingOrderStatus $to, ?OrderEvent $event, User $actor, array $context = []): void
    {
        $kind = match (true) {
            $from === null => NotificationKind::OrderPlaced,
            $event === OrderEvent::InfoRequested => NotificationKind::OrderInfoRequested,
            $event === OrderEvent::InfoAnswered => NotificationKind::InfoAnswered,
            $event === OrderEvent::PriceChanged => NotificationKind::OrderPriceChanged,
            $event === OrderEvent::PaymentRecorded => NotificationKind::PaymentRecorded,
            $event === OrderEvent::PaymentVoided => NotificationKind::PaymentVoided,
            $event !== null => null,
            $to === PrintingOrderStatus::Accepted => NotificationKind::OrderAccepted,
            $to === PrintingOrderStatus::Rejected => NotificationKind::OrderRejected,
            $to === PrintingOrderStatus::Processing => NotificationKind::OrderProcessing,
            $to === PrintingOrderStatus::ReadyForPickup => NotificationKind::OrderReady,
            $to === PrintingOrderStatus::Completed => NotificationKind::OrderCompleted,
            // Who cancelled decides who is told: a customer tells the owners, the shop tells the customer
            $to === PrintingOrderStatus::Cancelled => $actor->role === UserRole::Customer ? NotificationKind::OrderCancelledByCustomer : NotificationKind::OrderCancelledByShop,
            default => null,
        };

        $this->send($kind, $order, $actor, $context);
    }

    /** @param  array{payment?: string, amount?: string}  $context */
    public function bookingHistory(PhotographyBooking $booking, ?BookingStatus $from, BookingStatus $to, ?BookingEvent $event, User $actor, array $context = []): void
    {
        $kind = match (true) {
            $from === null => NotificationKind::BookingRequested,
            $event === BookingEvent::ProposalSent => NotificationKind::BookingProposed,
            $event === BookingEvent::ProposalAccepted => NotificationKind::ProposalAccepted,
            $event === BookingEvent::TimeRequestedAgain => NotificationKind::AnotherTimeRequested,
            $event === BookingEvent::PriceChanged => NotificationKind::BookingPriceChanged,
            $event === BookingEvent::PaymentRecorded => NotificationKind::PaymentRecorded,
            $event === BookingEvent::PaymentVoided => NotificationKind::PaymentVoided,
            $event !== null => null,
            $to === BookingStatus::Confirmed => NotificationKind::BookingConfirmed,
            $to === BookingStatus::Rejected => NotificationKind::BookingRejected,
            $to === BookingStatus::Completed => NotificationKind::BookingCompleted,
            $to === BookingStatus::Cancelled => $actor->role === UserRole::Customer ? NotificationKind::BookingCancelledByCustomer : NotificationKind::BookingCancelledByShop,
            default => null,
        };

        $this->send($kind, $booking, $actor, $context);
    }

    /** A customer wrote in their studio thread: every owner is told, once until they read it (Phase 7B). */
    public function messageReceived(Conversation $conversation, User $customer): void
    {
        $this->sendMessageNotice(NotificationKind::MessageReceived, $conversation, $customer,
            fn () => User::query()->where('role', UserRole::Owner)->whereKeyNot($customer->id)->get());
    }

    /** The owner replied in a studio thread: the customer is told, once until they read it. Lumi's replies tell nobody. */
    public function messageReplied(Conversation $conversation, User $owner): void
    {
        $this->sendMessageNotice(NotificationKind::MessageReplied, $conversation, $owner,
            fn () => User::query()->whereKey($conversation->customer_id)->where('role', UserRole::Customer)->get());
    }

    /** Opening a thread marks the reader's unread message notices of that conversation read. */
    public function markConversationRead(User $reader, Conversation $conversation): void
    {
        $reader->unreadNotifications()->reorder()
            ->whereIn('type', [NotificationKind::MessageReceived->value, NotificationKind::MessageReplied->value])
            ->where('data->conversation_id', $conversation->id)
            ->update(['read_at' => now()]);
    }

    /**
     * Coalesced: a recipient who still has an UNREAD notice of the same kind for this conversation gets no second one.
     * The data is the conversation id and the sender's name only, never the message.
     *
     * @param  callable(): Collection<int, User>  $recipients
     */
    private function sendMessageNotice(NotificationKind $kind, Conversation $conversation, User $sender, callable $recipients): void
    {
        $data = ['kind' => $kind->value, 'conversation_id' => (int) $conversation->id, 'sender_name' => (string) $sender->name];

        DB::afterCommit(function () use ($kind, $data, $recipients) {
            try {
                $waiting = DatabaseNotification::query()->where('type', $kind->value)->whereNull('read_at')
                    ->where('data->conversation_id', $data['conversation_id'])->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all();

                $to = $recipients()->reject(fn (User $user) => in_array($user->id, $waiting, true));

                Notification::sendNow($to, new ActivityNotification($data));
            } catch (Throwable $e) {
                Log::warning('A notification could not be saved.', ['kind' => $kind->value, 'conversation' => $data['conversation_id'], 'error' => $e::class]);
            }
        });
    }

    /** @param  array{payment?: string, amount?: string}  $context */
    private function send(?NotificationKind $kind, PrintingOrder|PhotographyBooking $payable, User $actor, array $context): void
    {
        // Only the other side is told: a kind for customers needs the shop to have acted, and the reverse
        if ($kind === null || $kind->audience() === $actor->role) {
            return;
        }

        $data = array_filter([
            'kind' => $kind->value,
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => (int) $payable->getKey(),
            'reference' => (string) $payable->reference_number,
            'amount' => $context['amount'] ?? (in_array($kind, [NotificationKind::OrderAccepted, NotificationKind::OrderPriceChanged, NotificationKind::BookingPriceChanged], true) ? $payable->confirmed_price : null),
            'payment' => $context['payment'] ?? null,
        ], fn ($value) => $value !== null);

        $customerId = (int) $payable->customer_id;
        $actorId = (int) $actor->getKey();

        DB::afterCommit(function () use ($kind, $data, $customerId, $actorId) {
            try {
                $recipients = ($kind->audience() === UserRole::Owner
                    ? User::query()->where('role', UserRole::Owner)
                    : User::query()->whereKey($customerId)->where('role', UserRole::Customer))
                    ->whereKeyNot($actorId)
                    ->get();

                Notification::sendNow($recipients, new ActivityNotification($data));
            } catch (Throwable $e) {
                // The action already succeeded; only the notice is lost. No names, emails or text from the record here
                Log::warning('A notification could not be saved.', ['kind' => $kind->value, 'payable' => $data['payable_type'].'#'.$data['payable_id'], 'error' => $e::class]);
            }
        });
    }
}
