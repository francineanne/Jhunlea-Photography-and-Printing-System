<?php

namespace App\Services\Assistant;

use App\Enums\AssistantCardType;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\Theme;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Recommendation cards, built only from the database: the name, price wording and link always come from the published
 * record itself, never from the model. A recommendation that points to a missing or unpublished record is dropped
 * without a word to the customer; only the count is logged.
 *
 * Record cards (Phase 7E-1) show one of the customer's own orders or bookings: loaded only WHERE customer_id is the
 * given customer, so a forged, foreign or missing id is dropped the same way. Without a customer there are none.
 */
class CardBuilder
{
    public function __construct(private readonly CustomerContextBuilder $records) {}

    /**
     * @param  list<array{type: AssistantCardType, id: int}>  $recommend
     * @return list<array{type: string, id: int, name: string, price: ?string, summary: ?string, inclusions: list<string>, url: string}>
     */
    public function build(array $recommend, ?int $customerId = null): array
    {
        return $this->buildMany([$recommend], $customerId)[0];
    }

    /**
     * The cards of several replies at once (a page of chat messages), with at most one query per type for all of them
     * together, and none for a type nobody recommended. $ownerLinks points record cards to the owner's pages.
     *
     * @param  array<array-key, list<array{type: AssistantCardType, id: int}>>  $lists
     * @return array<array-key, list<array{type: string, id: int, name: string, price: ?string, summary: ?string, inclusions: list<string>, url: string}>>
     */
    public function buildMany(array $lists, ?int $customerId = null, bool $ownerLinks = false): array
    {
        $all = array_merge([], ...array_values($lists));
        $ids = fn (AssistantCardType $type) => array_values(array_unique(array_column(array_filter($all, fn ($item) => $item['type'] === $type), 'id')));
        $load = fn (AssistantCardType $type, string $model) => $ids($type) === [] ? collect() : $model::query()->published()->whereKey($ids($type))->get()->keyBy('id');
        $own = fn (AssistantCardType $type, string $model) => $customerId === null || $ids($type) === [] ? collect()
            : $model::query()->where('customer_id', $customerId)->whereKey($ids($type))->with('recordedPayment')->get()->keyBy('id');

        $records = [
            AssistantCardType::Service->value => $load(AssistantCardType::Service, PrintingService::class),
            AssistantCardType::Package->value => $load(AssistantCardType::Package, PhotographyPackage::class),
            AssistantCardType::Theme->value => $load(AssistantCardType::Theme, Theme::class),
            AssistantCardType::Order->value => $own(AssistantCardType::Order, PrintingOrder::class),
            AssistantCardType::Booking->value => $own(AssistantCardType::Booking, PhotographyBooking::class),
        ];

        $dropped = 0;
        $result = [];

        foreach ($lists as $key => $recommend) {
            $cards = [];

            foreach ($recommend as $item) {
                $record = $records[$item['type']->value]->get($item['id']);
                $record === null ? $dropped++ : $cards[] = $this->card($record, $ownerLinks);
            }

            $result[$key] = array_slice($cards, 0, (int) config('assistant.max_cards'));
        }

        if ($dropped > 0) {
            Log::info('assistant.cards_dropped', ['dropped' => $dropped]);
        }

        return $result;
    }

    /** @return array{type: string, id: int, name: string, price: ?string, summary: ?string, inclusions: list<string>, url: string} */
    private function card(PrintingService|PhotographyPackage|Theme|PrintingOrder|PhotographyBooking $record, bool $ownerLinks): array
    {
        if ($record instanceof PrintingOrder || $record instanceof PhotographyBooking) {
            return $this->recordCard($record, $ownerLinks);
        }

        return match (true) {
            $record instanceof PrintingService => [
                'type' => AssistantCardType::Service->value,
                'id' => $record->id,
                'name' => $record->name,
                'price' => $record->priceText(),
                'summary' => $this->summary($record->description),
                'inclusions' => [],
                'url' => route('services.index', ['q' => $record->name]),
            ],
            $record instanceof PhotographyPackage => [
                'type' => AssistantCardType::Package->value,
                'id' => $record->id,
                'name' => $record->name,
                'price' => $record->priceText(),
                'summary' => $this->summary($record->short_description),
                'inclusions' => array_slice(array_values($record->inclusions ?? []), 0, 3),
                'url' => route('packages.show', $record->slug),
            ],
            default => [
                'type' => AssistantCardType::Theme->value,
                'id' => $record->id,
                'name' => $record->name,
                'price' => null,
                'summary' => $this->summary($record->description),
                'inclusions' => [],
                'url' => route('packages.index', ['theme' => $record->id]),
            ],
        };
    }

    /**
     * One of the customer's own records: its reference and name, what is left to pay, the status (and session time)
     * as the customer's own pages say it, and the link to that page (the owner's page when the owner reads the thread).
     *
     * @return array{type: string, id: int, name: string, price: ?string, summary: ?string, inclusions: list<string>, url: string}
     */
    private function recordCard(PrintingOrder|PhotographyBooking $record, bool $ownerLinks): array
    {
        $fact = $this->records->describe($record);
        $order = $record instanceof PrintingOrder;

        return [
            'type' => $fact->type->value,
            'id' => $record->id,
            'name' => $fact->reference.' · '.$fact->name,
            'price' => $fact->paymentShort,
            'summary' => 'Status: '.$fact->statusLabel.($fact->schedule === null ? '' : '. '.$fact->schedule),
            'inclusions' => [],
            'url' => route(match (true) {
                $order && $ownerLinks => 'owner.orders.show',
                $order => 'customer.printing.show',
                $ownerLinks => 'owner.bookings.show',
                default => 'customer.bookings.show',
            }, $record->id),
        ];
    }

    private function summary(?string $text): ?string
    {
        return $text === null || trim($text) === '' ? null : Str::limit(trim($text), 140);
    }
}
