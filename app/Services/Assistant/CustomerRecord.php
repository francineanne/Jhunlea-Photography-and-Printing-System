<?php

namespace App\Services\Assistant;

use App\Enums\AssistantCardType;

/**
 * One of the signed-in customer's own printing orders or photography bookings, as the facts the customer already sees on
 * their My orders and My bookings pages (Phase 7E-1). It never holds free text (notes, reasons, the shop's questions),
 * an owner note, a contact detail, a file or anything of another customer. CustomerContextBuilder makes it.
 */
final class CustomerRecord
{
    public function __construct(
        public readonly AssistantCardType $type,
        public readonly int $id,
        public readonly string $reference,
        public readonly string $name,
        public readonly string $statusLabel,
        public readonly bool $finished,
        // The session time line of a booking (requested, proposed or confirmed), null for an order
        public readonly ?string $schedule,
        public readonly string $price,
        public readonly string $payment,
        // The short payment words for a card: "₱1,500.00 to pay", "Awaiting price", "Paid"
        public readonly string $paymentShort,
        public readonly bool $cancellable,
        public readonly bool $needsInfo,
        public readonly string $lastUpdate,
        // What the record shows, for ClaimGuard: confirmed, ready, completed, paid
        public readonly array $states,
    ) {}

    public function kindLabel(): string
    {
        return $this->type === AssistantCardType::Order ? 'Printing order' : 'Photoshoot booking';
    }

    /** One line of the prompt block. */
    public function line(): string
    {
        return '- '.implode(' ', array_filter([
            "{$this->kindLabel()} {$this->reference} (type \"{$this->type->value}\", id {$this->id}): {$this->name}.",
            "Status: {$this->statusLabel}.",
            $this->needsInfo ? 'The shop asked the customer for more information (shown on the order page).' : null,
            $this->schedule === null ? null : $this->schedule.'.',
            "Price: {$this->price}.",
            "Payment: {$this->payment}.",
            $this->finished ? null : ($this->cancellable ? 'The customer can cancel it online now.' : 'The customer cannot cancel it online now.'),
            "Last update: {$this->lastUpdate}.",
        ]));
    }

    /** @return array{type: string, id: int} the chat message "about" reference (morph alias + id) */
    public function about(): array
    {
        return ['type' => (string) $this->type->morphAlias(), 'id' => $this->id];
    }
}
