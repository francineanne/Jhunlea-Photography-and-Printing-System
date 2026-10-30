<?php

namespace App\Services\Assistant;

use App\Enums\AssistantCardType;
use App\Models\PhotographyPackage;
use App\Models\PrintingService;
use App\Models\Theme;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Recommendation cards, built only from the database: the name, price wording and link always come from the published
 * record itself, never from the model. A recommendation that points to a missing or unpublished record is dropped
 * without a word to the customer; only the count is logged.
 */
class CardBuilder
{
    /**
     * @param  list<array{type: AssistantCardType, id: int}>  $recommend
     * @return list<array{type: string, id: int, name: string, price: ?string, summary: ?string, inclusions: list<string>, url: string}>
     */
    public function build(array $recommend): array
    {
        return $this->buildMany([$recommend])[0];
    }

    /**
     * The cards of several replies at once (a page of chat messages), with at most one query per catalog type for all of
     * them together, and none for a type nobody recommended.
     *
     * @param  array<array-key, list<array{type: AssistantCardType, id: int}>>  $lists
     * @return array<array-key, list<array{type: string, id: int, name: string, price: ?string, summary: ?string, inclusions: list<string>, url: string}>>
     */
    public function buildMany(array $lists): array
    {
        $all = array_merge([], ...array_values($lists));
        $ids = fn (AssistantCardType $type) => array_values(array_unique(array_column(array_filter($all, fn ($item) => $item['type'] === $type), 'id')));
        $load = fn (AssistantCardType $type, string $model) => $ids($type) === [] ? collect() : $model::query()->published()->whereKey($ids($type))->get()->keyBy('id');

        $records = [
            AssistantCardType::Service->value => $load(AssistantCardType::Service, PrintingService::class),
            AssistantCardType::Package->value => $load(AssistantCardType::Package, PhotographyPackage::class),
            AssistantCardType::Theme->value => $load(AssistantCardType::Theme, Theme::class),
        ];

        $dropped = 0;
        $result = [];

        foreach ($lists as $key => $recommend) {
            $cards = [];

            foreach ($recommend as $item) {
                $record = $records[$item['type']->value]->get($item['id']);
                $record === null ? $dropped++ : $cards[] = $this->card($record);
            }

            $result[$key] = array_slice($cards, 0, (int) config('assistant.max_cards'));
        }

        if ($dropped > 0) {
            Log::info('assistant.cards_dropped', ['dropped' => $dropped]);
        }

        return $result;
    }

    /** @return array{type: string, id: int, name: string, price: ?string, summary: ?string, inclusions: list<string>, url: string} */
    private function card(PrintingService|PhotographyPackage|Theme $record): array
    {
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

    private function summary(?string $text): ?string
    {
        return $text === null || trim($text) === '' ? null : Str::limit(trim($text), 140);
    }
}
