<?php

namespace App\Services\Chat;

use App\Enums\AssistantCardType;
use App\Enums\MessageSender;
use App\Enums\UserRole;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Assistant\CardBuilder;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The JSON of conversations and messages (Phase 7B). Bodies are plain strings, never HTML. Cards are rebuilt from the
 * catalog on every read (an unpublished item disappears, a price is always current), and the orders and bookings a
 * message is about are loaded once per page: a page costs the same number of queries however many messages it holds.
 * Nobody's email, phone or user id is ever included.
 */
class ChatPresenter
{
    public function __construct(
        private readonly CardBuilder $cards,
        private readonly OwnerPresence $presence,
        private readonly MessageService $messages,
        private readonly LumiReplyService $lumi,
    ) {}

    /**
     * @param  Collection<int, ChatMessage>  $messages
     * @return array<string, mixed>
     */
    public function thread(Conversation $conversation, User $viewer, Collection $messages, bool $hasMoreBefore, bool $hasMoreAfter = false): array
    {
        return [
            'conversation' => $this->conversation($conversation, $viewer),
            'messages' => $this->messages($conversation, $viewer, $messages),
            'state' => $this->state($conversation, $viewer, $hasMoreBefore, $hasMoreAfter),
        ];
    }

    /** @return array<string, mixed> */
    public function conversation(Conversation $conversation, User $viewer): array
    {
        return array_filter([
            'id' => $conversation->id,
            'kind' => $conversation->kind->value,
            'customer' => $viewer->role === UserRole::Owner ? ['name' => $conversation->customer->name, 'url' => route('owner.customers.show', $conversation->customer_id)] : null,
            'needs_owner' => $conversation->needs_owner,
            'resolved' => $conversation->isResolved(),
            'last_message_at' => $this->time($conversation->last_message_at),
            'last_message_preview' => $conversation->last_message_preview,
            'last_sender_type' => $conversation->last_sender_type?->value,
            'created_at' => $this->time($conversation->created_at),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  Collection<int, ChatMessage>  $messages
     * @return list<array<string, mixed>>
     */
    public function messages(Conversation $conversation, User $viewer, Collection $messages): array
    {
        $messages = (new EloquentCollection($messages->all()))->loadMissing('sender:id,name,avatar_path,is_walk_in');
        $conversation->loadMissing('customer:id,name');

        // Record cards only ever show the conversation's own customer's orders and bookings
        $cards = $this->cards->buildMany(
            $messages->mapWithKeys(fn (ChatMessage $message) => [$message->id => $this->references($message)])->all(),
            $conversation->customer_id,
            $viewer->role === UserRole::Owner,
        );
        $about = $this->aboutReferences($messages, $viewer);

        return $messages->map(fn (ChatMessage $message) => [
            'id' => $message->id,
            'sender_type' => $message->sender_type->value,
            'sender_label' => $this->label($conversation, $message, $viewer),
            // Phase 10C: the picture of the person who wrote it (customer or owner), else their initials; never for Lumi
            'avatar' => $this->avatar($message),
            'body' => $message->body,
            'created_at' => $this->time($message->created_at),
            'mine' => $message->sender_id !== null && $message->sender_id === $viewer->id,
            'system_kind' => $message->systemKind()?->value,
            'intent' => $message->sender_type === MessageSender::Assistant ? ($message->meta['intent'] ?? null) : null,
            // The backup provider wrote it (the "Lumi Lite" badge, 7E-2a); which provider is never exposed
            'via_backup' => $message->sender_type === MessageSender::Assistant && ($message->meta['via_backup'] ?? false) === true,
            'cards' => $cards[$message->id] ?? [],
            'about' => $about[$message->id] ?? null,
            'via_lumi' => isset($message->meta['from_assistant_conversation']),
            // The Ask Lumi conversation is the customer's own; the owner never opens one, so never sees its id
            'from_assistant_conversation' => $viewer->role === UserRole::Customer ? ($message->meta['from_assistant_conversation'] ?? null) : null,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public function state(Conversation $conversation, User $viewer, bool $hasMoreBefore, bool $hasMoreAfter = false): array
    {
        $studio = null;

        if ($conversation->isStudio()) {
            $away = $this->presence->isAway();

            $studio = [
                'owner_away' => $away,
                // One away indication at a time: once Lumi or one of its notices spoke in this stretch, the thread
                // already says it, so the status line stays quiet
                'away_notice' => $away && ! $this->lumi->awayAlreadyShown($conversation) ? $this->presence->awayNotice() : null,
                'lumi_active' => $this->lumi->isActive($conversation),
                'waiting_for_studio' => $conversation->last_sender_type === MessageSender::Customer && ($away || $conversation->needs_owner),
                'needs_owner' => $conversation->needs_owner,
            ];

            // Phase 10E: the owner's thread says how many of Lumi's answers came while they were away (owner only)
            if ($viewer->role === UserRole::Owner) {
                $studio['lumi_answered'] = $this->lumi->answeredSinceOwner($conversation);
            }
        }

        return [
            'studio' => $studio,
            'unread_count' => $this->messages->unreadCount($conversation, $viewer),
            // Phase 10E: how far the OTHER person has read, for "Seen". Only a person's own read marker counts; Lumi
            // reading or answering never moves the studio's marker
            'seen_up_to' => (int) ($viewer->role === UserRole::Owner ? $conversation->customer_last_read_message_id : $conversation->owner_last_read_message_id),
            'poll' => config('chat.poll'),
            'has_more_before' => $hasMoreBefore,
            'has_more_after' => $hasMoreAfter,
        ];
    }

    /** @return array{iso: string, local: string}|null */
    public function time(mixed $value): ?array
    {
        return $value === null ? null : ['iso' => LocalTime::iso($value), 'local' => LocalTime::format($value)];
    }

    /** @return array{url: ?string, initials: string}|null */
    private function avatar(ChatMessage $message): ?array
    {
        if (! in_array($message->sender_type, [MessageSender::Customer, MessageSender::Owner], true) || $message->sender === null) {
            return null;
        }

        return ['url' => $message->sender->avatarUrl(), 'initials' => $message->sender->initials()];
    }

    private function label(Conversation $conversation, ChatMessage $message, User $viewer): string
    {
        // The viewer's own messages are always "You", never their stored name
        if ($message->sender_id !== null && $message->sender_id === $viewer->id) {
            return 'You';
        }

        return match ($message->sender_type) {
            MessageSender::Customer => $conversation->customer?->name ?? 'Customer',
            MessageSender::Owner => $message->sender?->name ?? 'Studio',
            MessageSender::Assistant => config('assistant.name').' (AI)',
            MessageSender::System => 'Studio',
        };
    }

    /** @return list<array{type: AssistantCardType, id: int}> */
    private function references(ChatMessage $message): array
    {
        if ($message->sender_type !== MessageSender::Assistant || ! is_array($message->meta['recommend'] ?? null)) {
            return [];
        }

        $references = [];

        foreach ($message->meta['recommend'] as $item) {
            $type = AssistantCardType::tryFrom((string) ($item['type'] ?? ''));

            if ($type !== null && is_int($item['id'] ?? null)) {
                $references[] = ['type' => $type, 'id' => $item['id']];
            }
        }

        return $references;
    }

    /**
     * The order or booking each message is about, with its reference number; one query per type for the whole page.
     *
     * @param  Collection<int, ChatMessage>  $messages
     * @return array<int, array{type: string, id: int, reference: string, url: string}>
     */
    private function aboutReferences(Collection $messages, User $viewer): array
    {
        $about = $messages->filter(fn (ChatMessage $message) => is_array($message->meta['about'] ?? null))
            ->mapWithKeys(fn (ChatMessage $message) => [$message->id => $message->meta['about']]);

        $found = [];

        // Phase 10E: the card says what the record is (one query per type for the whole page, as before)
        foreach (['printing_order' => PrintingOrder::class, 'photography_booking' => PhotographyBooking::class] as $type => $model) {
            $ids = $about->where('type', $type)->pluck('id')->unique()->values()->all();
            $found[$type] = $ids === [] ? [] : $model::query()->whereKey($ids)->with('payments')->get()->keyBy('id')->all();
        }

        $owner = $viewer->role === UserRole::Owner;
        $route = fn (string $type) => match ($type) {
            'printing_order' => $owner ? 'owner.orders.show' : 'customer.printing.show',
            default => $owner ? 'owner.bookings.show' : 'customer.bookings.show',
        };

        return $about->map(function (array $item) use ($found, $route) {
            $record = $found[$item['type']][$item['id']] ?? null;

            return $record === null ? null : ['type' => $item['type'], 'id' => (int) $item['id'], 'url' => route($route($item['type']), (int) $item['id'])]
                + ChatRecords::card($record);
        })->filter()->all();
    }
}
