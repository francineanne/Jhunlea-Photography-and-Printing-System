<?php

namespace App\Support\Finder;

/**
 * What the visitor told the package finder. Every answer is optional. Read from the query string with the same
 * rule as the list filters: a value that is not valid is ignored, never reported.
 */
class FinderAnswers
{
    public function __construct(
        public readonly ?string $category = null,
        public readonly ?int $people = null,
        public readonly ?float $budget = null,
        public readonly ?int $theme = null,
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query): self
    {
        $category = $query['category'] ?? null;
        $people = $query['people'] ?? null;
        $budget = $query['budget'] ?? null;
        $theme = $query['theme'] ?? null;

        return new self(
            category: is_string($category) && trim($category) !== '' ? mb_substr(trim($category), 0, 60) : null,
            people: is_string($people) && preg_match('/^\d{1,3}$/', $people) && (int) $people >= 1 && (int) $people <= 500 ? (int) $people : null,
            budget: is_string($budget) && preg_match('/^\d{1,8}(\.\d{1,2})?$/', $budget) && (float) $budget > 0 ? (float) $budget : null,
            theme: is_string($theme) && preg_match('/^\d{1,9}$/', $theme) && (int) $theme >= 1 ? (int) $theme : null,
        );
    }

    /** The same answers with the category spelled as the catalog spells it (the address may use other letter case). */
    public function withCategory(?string $category): self
    {
        return new self($category, $this->people, $this->budget, $this->theme);
    }

    public function isEmpty(): bool
    {
        return $this->category === null && $this->people === null && $this->budget === null && $this->theme === null;
    }

    /** @return array<string, int|float|string> */
    public function toQuery(): array
    {
        return array_filter([
            'category' => $this->category,
            'people' => $this->people,
            'budget' => $this->budget !== null ? rtrim(rtrim(number_format($this->budget, 2, '.', ''), '0'), '.') : null,
            'theme' => $this->theme,
        ], fn ($value) => $value !== null);
    }
}
