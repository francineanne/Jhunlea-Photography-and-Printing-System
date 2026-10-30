<?php

namespace App\Services\Chat;

use App\Enums\ConversationKind;
use App\Enums\MessageSender;
use App\Enums\SystemMessageKind;
use App\Enums\UserRole;
use App\Exceptions\ChatException;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\NotificationDispatcher;
use App\Support\LocalTime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sends, reads and resolves messages (Phase 7B). People have no length limit on a conversation, only anti-spam limits:
 * config chat.per_day_customer_messages a Manila day, and config chat.max_unanswered_customer_messages in a row in the
 * studio thread. An Ask Lumi conversation holds config chat.assistant_conversation_max_messages messages in all.
 *
 * Every send is idempotent on its client_id: the same client_id stores one row and returns it again.
 */
class MessageService
{
    /** The orders and bookings a customer message may be "about" (morph map aliases). */
    private const ABOUT = ['printing_order' => PrintingOrder::class, 'photography_booking' => PhotographyBooking::class];

    public function __construct(
        private readonly NotificationDispatcher $notifications,
        private readonly OwnerPresence $presence,
    ) {}

    /**
     * @param  array{type?: mixed, id?: mixed}|null  $about
     * @return array{0: ChatMessage, 1: bool} the message and whether it was stored now (false: a retried client_id)
     */
    public function sendFromCustomer(Conversation $conversation, User $customer, string $body, ?string $clientId = null, ?array $about = null, ?int $fromAssistantConversation = null): array
    {
        $body = $this->normalize($body);

        if ($repeat = $this->byClientId($conversation, $clientId)) {
            return [$repeat, false];
        }

        $meta = array_filter([
            'about' => $about === null ? null : $this->about($customer, $about),
            'from_assistant_conversation' => $fromAssistantConversation === null ? null : $this->fromAssistant($conversation, $customer, $fromAssistantConversation),
        ]);

        return $this->store($conversation, $clientId, function (Conversation $locked) use ($customer, $body, $meta, $clientId) {
            $this->checkCustomerLimits($locked, $customer);

            return $this->insert($locked, MessageSender::Customer, $customer->id, $body, $meta ?: null, $clientId);
        }, fn (ChatMessage $message) => $conversation->isStudio() ? $this->notifications->messageReceived($conversation, $customer) : null);
    }

    /** @return array{0: ChatMessage, 1: bool} */
    public function sendFromOwner(Conversation $conversation, User $owner, string $body, ?string $clientId = null): array
    {
        $body = $this->normalize($body);
        $this->presence->touch();

        if ($repeat = $this->byClientId($conversation, $clientId)) {
            return [$repeat, false];
        }

        return $this->store($conversation, $clientId, function (Conversation $locked) use ($owner, $body, $clientId) {
            // Lumi was answering for the away owner: say clearly that a person has taken over
            $lastReply = $locked->messages()->whereIn('sender_type', [MessageSender::Owner, MessageSender::Assistant])->latest('id')->first();

            if ($lastReply?->sender_type === MessageSender::Assistant) {
                $this->insert($locked, MessageSender::System, null, config('chat.messages.handoff_to_owner'), ['system_kind' => SystemMessageKind::OwnerJoined->value]);
            }

            $message = $this->insert($locked, MessageSender::Owner, $owner->id, $body, null, $clientId);
            $locked->forceFill(['needs_owner' => false, 'needs_owner_at' => null, 'owner_last_read_message_id' => $message->id])->save();

            return $message;
        }, fn (ChatMessage $message) => $this->notifications->messageReplied($conversation, $owner));
    }

    /** Clears "needs the owner" without sending anything. */
    public function resolve(Conversation $conversation): void
    {
        $conversation->forceFill(['needs_owner' => false, 'needs_owner_at' => null])->save();
    }

    /**
     * Marks the conversation read up to a message (the newest when none is given) for the customer or the owner. The
     * marker only ever moves forward. The reader's unread message notifications of this conversation are marked too.
     */
    public function markRead(Conversation $conversation, User $reader, ?int $upTo = null): void
    {
        $newest = (int) $conversation->messages()->max('id');
        $target = $upTo === null ? $newest : min($upTo, $newest);
        $column = $reader->role === UserRole::Owner ? 'owner_last_read_message_id' : 'customer_last_read_message_id';

        if ($target > 0) {
            Conversation::query()->whereKey($conversation->id)
                ->where(fn ($query) => $query->whereNull($column)->orWhere($column, '<', $target))
                ->update([$column => $target]);
        }

        $this->notifications->markConversationRead($reader, $conversation);
    }

    /** Messages the viewer has not read: the other side's (and Lumi's and notices) for the customer, the customer's for the owner. */
    public function unreadCount(Conversation $conversation, User $viewer): int
    {
        $owner = $viewer->role === UserRole::Owner;
        $marker = (int) ($owner ? $conversation->owner_last_read_message_id : $conversation->customer_last_read_message_id);

        return $conversation->messages()->where('id', '>', $marker)
            ->when($owner, fn ($query) => $query->where('sender_type', MessageSender::Customer), fn ($query) => $query->where('sender_type', '!=', MessageSender::Customer))
            ->count();
    }

    /**
     * A draft for the studio thread built from the customer's own last 3 messages of an Ask Lumi conversation, without
     * the AI. The customer edits it before sending.
     */
    public function handoffDraft(Conversation $assistantConversation): string
    {
        $asked = $assistantConversation->messages()->where('sender_type', MessageSender::Customer)->latest('id')->limit(3)->pluck('body')->reverse();
        $lines = $asked->map(fn (string $body) => '- '.preg_replace('/\s+/u', ' ', trim($body)))->implode("\n");

        return mb_substr(trim("Hi, I was asking Lumi about:\n".$lines), 0, (int) config('chat.message_max_chars'));
    }

    /** Trimmed plain text with unified newlines and no control characters; empty or too long is refused. */
    public function normalize(string $body): string
    {
        if (! mb_check_encoding($body, 'UTF-8')) {
            throw ChatException::invalidMessage();
        }

        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = trim(preg_replace('/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{7F}-\x{9F}]/u', '', $body) ?? '');

        if ($body === '' || mb_strlen($body) > (int) config('chat.message_max_chars')) {
            throw ChatException::invalidMessage();
        }

        return $body;
    }

    /**
     * Runs the insert under a lock on the conversation row, so limits are checked against what is really stored; then
     * notifies. A client_id that another request stored at the same moment returns that message instead.
     *
     * @param  callable(Conversation): ChatMessage  $insert
     * @param  callable(ChatMessage): mixed  $after
     * @return array{0: ChatMessage, 1: bool}
     */
    private function store(Conversation $conversation, ?string $clientId, callable $insert, callable $after): array
    {
        try {
            $message = DB::transaction(function () use ($conversation, $insert) {
                $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
                $message = $insert($locked);
                $conversation->setRawAttributes($locked->getAttributes(), true);

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            return [$this->byClientId($conversation, $clientId) ?? throw ChatException::invalidMessage(), false];
        }

        $after($message);

        return [$message, true];
    }

    private function checkCustomerLimits(Conversation $conversation, User $customer): void
    {
        if ($conversation->kind === ConversationKind::Assistant && $conversation->messages()->count() >= (int) config('chat.assistant_conversation_max_messages')) {
            throw ChatException::conversationFull();
        }

        $today = LocalTime::dayStartUtc(Carbon::now(config('app.display_timezone'))->toDateString());
        $sentToday = ChatMessage::query()->where('sender_type', MessageSender::Customer)->where('sender_id', $customer->id)->where('created_at', '>=', $today)->count();

        if ($sentToday >= (int) config('chat.per_day_customer_messages')) {
            throw ChatException::dailyCap();
        }

        if ($conversation->isStudio()) {
            $lastReply = (int) $conversation->messages()->whereIn('sender_type', [MessageSender::Owner, MessageSender::Assistant])->max('id');
            $streak = $conversation->messages()->where('sender_type', MessageSender::Customer)->where('id', '>', $lastReply)->count();

            if ($streak >= (int) config('chat.max_unanswered_customer_messages')) {
                throw ChatException::waitingForStudio();
            }
        }
    }

    /** @param  array<string, mixed>|null  $meta */
    public function insert(Conversation $conversation, MessageSender $sender, ?int $senderId, string $body, ?array $meta, ?string $clientId = null): ChatMessage
    {
        $message = new ChatMessage;
        $message->forceFill(['conversation_id' => $conversation->id, 'sender_type' => $sender, 'sender_id' => $senderId, 'body' => $body, 'meta' => $meta, 'client_id' => $clientId])->save();

        ConversationService::touchLast($conversation, $message);

        return $message;
    }

    private function byClientId(Conversation $conversation, ?string $clientId): ?ChatMessage
    {
        return $clientId === null ? null : $conversation->messages()->where('client_id', $clientId)->first();
    }

    /**
     * The customer's own order or booking, as a reference. Anything else (another customer's, a missing one, a type that
     * is not allowed) is refused.
     *
     * @param  array{type?: mixed, id?: mixed}  $about
     * @return array{type: string, id: int}
     */
    private function about(User $customer, array $about): array
    {
        $class = self::ABOUT[$about['type'] ?? ''] ?? null;
        $id = filter_var($about['id'] ?? null, FILTER_VALIDATE_INT);

        if ($class === null || $id === false || ! $class::query()->whereKey($id)->where('customer_id', $customer->id)->exists()) {
            throw ChatException::invalid('invalid_about');
        }

        return ['type' => (string) $about['type'], 'id' => $id];
    }

    /** Only the customer's own Ask Lumi conversation, and only when the message goes to the studio thread. */
    private function fromAssistant(Conversation $target, User $customer, int $id): int
    {
        if (! $target->isStudio() || ! Conversation::query()->assistant()->forCustomer($customer)->whereKey($id)->exists()) {
            throw ChatException::invalid('invalid_from_assistant');
        }

        return $id;
    }
}
