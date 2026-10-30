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
        $ids = fn (AssistantCardType $type) => array_column(array_filter($recommend, fn ($item) => $item['type'] === $type), 'id');

        $records = [
            AssistantCardType::Service->value => PrintingService::query()->published()->whereKey($ids(AssistantCardType::Service))->get()->keyBy('id'),
            AssistantCardType::Package->value => PhotographyPackage::query()->published()->whereKey($ids(AssistantCardType::Package))->get()->keyBy('id'),
            AssistantCardType::Theme->value => Theme::query()->published()->whereKey($ids(AssistantCardType::Theme))->get()->keyBy('id'),
        ];

        $cards = [];

        foreach ($recommend as $item) {
            $record = $records[$item['type']->value]->get($item['id']);

            if ($record !== null) {
                $cards[] = $this->card($record);
            }
        }

        if (count($cards) < count($recommend)) {
            Log::info('assistant.cards_dropped', ['dropped' => count($recommend) - count($cards)]);
        }

        return array_slice($cards, 0, (int) config('assistant.max_cards'));
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
