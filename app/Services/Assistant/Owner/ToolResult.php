<?php

namespace App\Services\Assistant\Owner;

use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * What one owner tool found (Phase 7E-3a). $data is what the model reads (labelled DATA in the prompt), $rows at most
 * OwnerTool::MAX_ROWS of them with $total the true count, money in whole centavos and shown as "₱1,200.00", and $facts
 * every number and date the answer may use: NumberGuard keeps a sentence only when its numbers are among them. $cards
 * and $links point to the owner's own pages. A tool never puts a phone number, an email or a message text in a result.
 */
final class ToolResult
{
    /** @var list<string> */
    private array $facts = [];

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{label: string, url: string}>  $cards
     * @param  list<array{label: string, url: string}>  $links
     */
    public function __construct(
        public readonly string $tool,
        public array $data = [],
        public array $rows = [],
        public ?int $total = null,
        public array $cards = [],
        public array $links = [],
        // A question for the owner instead of an answer (two customers named Maria, an unknown reference)
        public ?string $question = null,
    ) {}

    /** @return list<string> */
    public function facts(): array
    {
        $facts = $this->facts;

        if ($this->total !== null) {
            $facts[] = (string) $this->total;
            $facts[] = (string) count($this->rows);
        }

        return array_values(array_unique($facts));
    }

    /** A peso amount from whole centavos: returned as "₱1,200.00" and added to the facts. */
    public function money(int $cents): string
    {
        $text = Money::format(Money::fromCents($cents));
        $this->facts[] = $text;

        return $text;
    }

    public function count(int $count): int
    {
        $this->facts[] = (string) $count;

        return $count;
    }

    public function percent(float $percent): string
    {
        $text = rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').'%';
        $this->facts[] = $text;

        return $text;
    }

    /** A shop-local calendar day (Y-m-d), returned as "Thu, Nov 5, 2026" and added to the facts. */
    public function day(?string $date): ?string
    {
        if ($date === null) {
            return null;
        }

        $this->facts[] = $date;

        return LocalTime::format(LocalTime::dayStartUtc($date), 'D, M j, Y');
    }

    /** A stored UTC moment shown in shop time ("Thu, Nov 5, 2026 2:00 PM"); its day and time are facts. */
    public function moment(CarbonInterface|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $this->facts[] = LocalTime::format($value, 'Y-m-d');
        $this->facts[] = LocalTime::format($value, 'g:i A');

        return LocalTime::format($value, 'D, M j, Y g:i A');
    }

    /** A plain number that is not money, a count or a day (minutes, tokens): added to the facts as it is. */
    public function number(int|float|string $value): string
    {
        $this->facts[] = (string) $value;

        return (string) $value;
    }

    /** A period's words ("today", "last month"): the answer may use them. */
    public function label(string $label): string
    {
        $this->facts[] = $label;

        return $label;
    }

    public function link(string $label, string $url): void
    {
        if (($path = self::ownerPath($url)) !== null) {
            $this->links[] = ['label' => $label, 'url' => $path];
        }
    }

    public function card(string $label, string $url): void
    {
        if (count($this->cards) < OwnerTool::MAX_ROWS && ($path = self::ownerPath($url)) !== null) {
            $this->cards[] = ['label' => $label, 'url' => $path];
        }
    }

    /**
     * A link of an answer is always a RELATIVE owner page (7E-3b): "/owner/..." with its query, from a route() the
     * tool built. Anything else (another host, another area, a scheme) is dropped.
     */
    public static function ownerPath(string $url): ?string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';

        if ($parts === false || ! preg_match('~^/owner(?:/[A-Za-z0-9/_.-]*)?$~', $path) || str_contains($path, '..')) {
            return null;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** @return array<string, mixed> the block the phrasing call reads */
    public function forPrompt(): array
    {
        return array_filter([
            'tool' => $this->tool,
            'data' => $this->data,
            'rows' => $this->rows,
            'total' => $this->total,
            'rows_shown' => $this->total === null ? null : count($this->rows),
            'question' => $this->question,
        ], fn ($value) => $value !== null && $value !== []);
    }
}
