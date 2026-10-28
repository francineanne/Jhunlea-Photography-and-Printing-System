<?php

namespace App\Support;

use App\Models\PhotographyPackage;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filters and sort of the public packages list, read from the query string.
 *
 * Every value is checked against a whitelist or clamped to a range. Anything else is ignored, never reported: a
 * visitor who edits the address by hand gets the plain list, not an error page. Nothing here reads the database
 * with raw input; values reach the query only as bindings.
 */
class PackageFilters
{
    public const SORTS = ['featured', 'price_asc', 'price_desc', 'duration', 'name'];

    public const MAX_TEXT = 60;

    public function __construct(
        public readonly ?string $category = null,
        public readonly ?int $theme = null,
        public readonly ?int $people = null,
        public readonly ?int $minutes = null,
        public readonly ?float $budget = null,
        public readonly string $sort = 'featured',
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query): self
    {
        $text = fn ($value) => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, self::MAX_TEXT) : null;
        $whole = fn ($value, int $min, int $max) => is_string($value) && preg_match('/^\d{1,9}$/', $value) && (int) $value >= $min && (int) $value <= $max ? (int) $value : null;

        $budget = $query['budget'] ?? null;

        return new self(
            category: $text($query['category'] ?? null),
            theme: $whole($query['theme'] ?? null, 1, PHP_INT_MAX),
            people: $whole($query['people'] ?? null, 1, 500),
            minutes: $whole($query['minutes'] ?? null, 1, 1440),
            budget: is_string($budget) && preg_match('/^\d{1,8}(\.\d{1,2})?$/', $budget) ? (float) $budget : null,
            sort: in_array($query['sort'] ?? null, self::SORTS, true) ? $query['sort'] : 'featured',
        );
    }

    public function isActive(): bool
    {
        return $this->category !== null || $this->theme !== null || $this->people !== null || $this->minutes !== null || $this->budget !== null;
    }

    /**
     * The query-string form of these filters, without the defaults, for links that keep them (chips, pagination, compare).
     *
     * @return array<string, int|float|string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'category' => $this->category,
            'theme' => $this->theme,
            'people' => $this->people,
            'minutes' => $this->minutes,
            'budget' => $this->budget !== null ? rtrim(rtrim(number_format($this->budget, 2, '.', ''), '0'), '.') : null,
            'sort' => $this->sort === 'featured' ? null : $this->sort,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  Builder<PhotographyPackage>  $query
     * @return Builder<PhotographyPackage>
     */
    public function apply(Builder $query): Builder
    {
        $query
            ->when($this->category !== null, fn ($q) => $q->where('category', $this->category))
            ->when($this->people !== null, fn ($q) => $q->where('max_people', '>=', $this->people))
            ->when($this->minutes !== null, fn ($q) => $q->where('duration_minutes', '<=', $this->minutes))
            // A package with no listed price has no amount to compare, so a budget leaves it out
            ->when($this->budget !== null, fn ($q) => $q->where('price', '<=', $this->budget))
            ->when($this->theme !== null, fn ($q) => $q->whereHas('themes', fn ($themes) => $themes->where('themes.id', $this->theme)->where('themes.is_published', true)));

        return match ($this->sort) {
            'price_asc' => $query->orderByRaw('price is null')->orderBy('price')->orderBy('name')->orderBy('id'),
            'price_desc' => $query->orderByRaw('price is null')->orderByDesc('price')->orderBy('name')->orderBy('id'),
            'duration' => $query->orderBy('duration_minutes')->orderBy('name')->orderBy('id'),
            'name' => $query->orderBy('name')->orderBy('id'),
            default => $query->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('name')->orderBy('id'),
        };
    }

    /** The same filters with the category spelled as the catalog spells it (the address may use other letter case). */
    public function withCategory(?string $category): self
    {
        return new self($category, $this->theme, $this->people, $this->minutes, $this->budget, $this->sort);
    }
}
