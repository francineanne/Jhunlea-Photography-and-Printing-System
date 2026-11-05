<?php

namespace App\Services\Assistant;

use App\Enums\AssistantCardType;

/**
 * The signed-in customer's own records for one engine call (Phase 7E-1), or none: a guest, the owner's assist and any
 * other user get none(), and their prompt has no records block at all. $customerId is the only customer whose records
 * a card may ever show.
 */
final class CustomerRecords
{
    public const HEADING = "THIS CUSTOMER'S OWN RECORDS";

    /** @param list<CustomerRecord> $records newest active first, then the most recent finished */
    private function __construct(
        public readonly ?int $customerId,
        public readonly array $records,
        private readonly ?string $block,
    ) {}

    public static function none(): self
    {
        return new self(null, [], null);
    }

    /** @param list<CustomerRecord> $records */
    public static function forCustomer(int $customerId, array $records, string $block): self
    {
        return new self($customerId, $records, $block);
    }

    /** The prompt block, or null when there is no signed-in customer. */
    public function block(): ?string
    {
        return $this->block;
    }

    public function find(AssistantCardType $type, int $id): ?CustomerRecord
    {
        foreach ($this->records as $record) {
            if ($record->type === $type && $record->id === $id) {
                return $record;
            }
        }

        return null;
    }

    /** The first of these records whose reference number (PR-000001, PB-000001) the text names. */
    public function mentionedIn(string $text): ?CustomerRecord
    {
        foreach (self::references($text) as $reference) {
            if (($record = $this->byReference($reference['reference'])) !== null) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Every reference number in a text with its byte offset, normalised to "PR-000001": any case, with or without the
     * hyphen ("pr000001", "PB 000001").
     *
     * @return list<array{reference: string, offset: int}>
     */
    public static function references(string $text): array
    {
        preg_match_all('/(?<![\p{L}\p{N}])P([RB])[\s-]?(\d{6})(?!\d)/iu', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return array_map(fn (array $match) => ['reference' => 'P'.strtoupper($match[1][0]).'-'.$match[2][0], 'offset' => $match[0][1]], $matches);
    }

    public function byReference(string $reference): ?CustomerRecord
    {
        foreach ($this->records as $record) {
            if (strcasecmp($record->reference, $reference) === 0) {
                return $record;
            }
        }

        return null;
    }

    /** Whether any record shows this state (confirmed, ready, completed, paid). */
    public function shows(string $state): bool
    {
        foreach ($this->records as $record) {
            if (in_array($state, $record->states, true)) {
                return true;
            }
        }

        return false;
    }
}
