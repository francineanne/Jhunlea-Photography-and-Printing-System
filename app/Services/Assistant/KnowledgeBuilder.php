<?php

namespace App\Services\Assistant;

use App\Models\AvailabilityRule;
use App\Models\BookingSetting;
use App\Models\Faq;
use App\Models\PhotographyPackage;
use App\Models\PrintingService;
use App\Models\Theme;
use App\Support\Money;
use App\Support\ShopSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The shop facts one prompt may use, read on the server from PUBLISHED catalog records, published FAQs, the business
 * info, the opening hours and the booking rules. It never reads users, orders, bookings, payments, notifications,
 * files or any other conversation.
 *
 * The fixed policies and a compact index of every published item (type, id, name) are always included. The details
 * of each item and the FAQs are ranked by how many words they share with the customer's latest message, and added
 * best first until config assistant.context_max_chars is reached.
 */
class KnowledgeBuilder
{
    private const DETAIL_MAX_CHARS = 700;

    private const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    // Words too common to tell two catalog items apart (English and Filipino)
    private const STOP_WORDS = ['the', 'and', 'for', 'you', 'your', 'are', 'can', 'what', 'how', 'with', 'have', 'there', 'this',
        'that', 'please', 'want', 'need', 'ang', 'mga', 'yung', 'ung', 'ako', 'kayo', 'niyo', 'nyo', 'ninyo', 'po', 'ba', 'may',
        'meron', 'pwede', 'puwede', 'gusto', 'magkano', 'paano', 'saan', 'kailan', 'lang', 'naman', 'din', 'rin', 'sana'];

    public function __construct(private readonly ShopSettings $shop) {}

    public function build(string $query): string
    {
        $services = PrintingService::query()->published()
            ->with(['options' => fn ($options) => $options->active()->orderBy('group')->orderBy('label')])
            ->orderBy('sort_order')->orderBy('name')->get();
        $packages = PhotographyPackage::query()->published()
            ->with(['themes' => fn ($themes) => $themes->where('themes.is_published', true)])
            ->orderBy('sort_order')->orderBy('name')->get();
        $themes = Theme::query()->published()->orderBy('name')->get();
        $faqs = Faq::query()->published()->ordered()->get();

        $max = (int) config('assistant.context_max_chars');
        $words = $this->words($query);

        $text = $this->fixed()."\n\nCATALOG INDEX (the only items you may recommend, by type and id)\n".$this->index($services, $packages, $themes);
        $text = Str::limit($text, $max, '');

        $blocks = [
            ...$services->map(fn (PrintingService $service) => $this->serviceBlock($service))->all(),
            ...$packages->map(fn (PhotographyPackage $package) => $this->packageBlock($package))->all(),
            ...$themes->map(fn (Theme $theme) => $this->themeBlock($theme))->all(),
            ...$faqs->map(fn (Faq $faq) => "[faq] Q: {$faq->question}\nA: {$faq->answer}")->all(),
        ];

        $text .= "\n\nDETAILS";

        foreach ($this->rank($blocks, $words) as $block) {
            $block = Str::limit($block, self::DETAIL_MAX_CHARS);

            if (mb_strlen($text) + mb_strlen($block) + 2 > $max) {
                continue;
            }

            $text .= "\n\n".$block;
        }

        return $text;
    }

    /** The business info, hours, booking rules and payment rules, always included. */
    private function fixed(): string
    {
        $lines = ['SHOP'];
        $lines[] = 'Name: '.($this->shop->shopName() ?? config('app.name'));

        foreach (['Address' => $this->shop->address(), 'Phone' => $this->shop->phone(), 'Email' => $this->shop->email()] as $label => $value) {
            if ($value !== null) {
                $lines[] = "{$label}: {$value}";
            }
        }

        $lines[] = "\nOPENING HOURS (Philippine time)";

        foreach (AvailabilityRule::week() as $weekday => $rule) {
            $lines[] = self::DAYS[$weekday].': '.($rule->is_open && $rule->opens_at && $rule->closes_at
                ? $this->time($rule->opens_at).' to '.$this->time($rule->closes_at)
                : 'closed');
        }

        $settings = BookingSetting::current();
        $cutoff = (int) config('booking.customer_cancel_cutoff_hours');

        $lines[] = "\nBOOKING RULES";
        $lines[] = '- A booking request is only a request. The owner confirms it; a requested time is not a confirmed appointment.';
        $lines[] = '- '.$settings->windowText();
        $lines[] = "- A customer may cancel a confirmed booking until {$cutoff} hours before it starts; after that they talk to the studio.";
        $lines[] = '- Printing orders are reviewed by the shop; an estimated price is not the confirmed price.';

        $lines[] = "\nPAYMENT RULES";
        $lines[] = '- Payment is made face-to-face at Jhunlea Photography & Printing, in cash or GCash. There is no online payment.';
        $lines[] = '- No partial payment and no downpayment: the full confirmed price is paid in one payment (for a photoshoot, on the day of the shoot).';
        $lines[] = '- Orders and photos are released only when fully paid. The system only records payments.';

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, PrintingService>  $services
     * @param  Collection<int, PhotographyPackage>  $packages
     * @param  Collection<int, Theme>  $themes
     */
    private function index(Collection $services, Collection $packages, Collection $themes): string
    {
        $rows = [
            ...$services->map(fn ($item) => "service #{$item->id} {$item->name}")->all(),
            ...$packages->map(fn ($item) => "package #{$item->id} {$item->name}")->all(),
            ...$themes->map(fn ($item) => "theme #{$item->id} {$item->name}")->all(),
        ];

        return $rows === [] ? '(nothing published yet)' : implode("\n", $rows);
    }

    private function serviceBlock(PrintingService $service): string
    {
        $lines = ["[service #{$service->id}] {$service->name}".($service->category ? " ({$service->category})" : '')];
        $lines[] = 'Price: '.$service->priceText();
        $this->add($lines, $service->description);

        $options = $service->options->groupBy(fn ($option) => $option->group->label())
            ->map(fn ($group, $name) => $name.': '.$group->map(fn ($option) => $option->label.((float) $option->price_modifier > 0 ? ' ('.Money::modifier($option->price_modifier).')' : ''))->implode(', '));

        if ($options->isNotEmpty()) {
            $lines[] = 'Options: '.$options->implode('; ');
        }

        if (! empty($service->allowed_file_types)) {
            $lines[] = 'Accepted files: '.implode(', ', $service->allowed_file_types);
        }

        $this->add($lines, $service->instructions, 'Instructions: ');

        return implode("\n", $lines);
    }

    private function packageBlock(PhotographyPackage $package): string
    {
        $lines = ["[package #{$package->id}] {$package->name}".($package->category ? " ({$package->category})" : '')];
        $lines[] = 'Price: '.$package->priceText();
        $this->add($lines, $package->short_description);

        $facts = array_filter([
            $package->duration_minutes ? "{$package->duration_minutes} minutes" : null,
            $package->max_people ? "up to {$package->max_people} people" : null,
            $package->digital_outputs ? "{$package->digital_outputs} digital photos" : null,
        ]);

        if ($facts !== []) {
            $lines[] = implode(', ', $facts);
        }

        if (! empty($package->inclusions)) {
            $lines[] = 'Inclusions: '.implode('; ', $package->inclusions);
        }

        if ($package->themes->isNotEmpty()) {
            $lines[] = 'Themes: '.$package->themes->pluck('name')->implode(', ');
        }

        $this->add($lines, $package->terms, 'Terms: ');

        return implode("\n", $lines);
    }

    private function themeBlock(Theme $theme): string
    {
        $lines = ["[theme #{$theme->id}] {$theme->name}"];
        $this->add($lines, $theme->description);

        return implode("\n", $lines);
    }

    /** @param list<string> $lines */
    private function add(array &$lines, ?string $value, string $label = ''): void
    {
        if ($value !== null && trim($value) !== '') {
            $lines[] = $label.trim($value);
        }
    }

    /**
     * The blocks with the most words in common with the message first; ties keep the catalog order.
     *
     * @param  list<string>  $blocks
     * @param  list<string>  $words
     * @return list<string>
     */
    private function rank(array $blocks, array $words): array
    {
        $scored = array_map(function (string $block, int $position) use ($words) {
            $lower = mb_strtolower($block);
            $title = mb_strtolower(strtok($block, "\n"));
            $score = 0;

            foreach ($words as $word) {
                $score += (str_contains($title, $word) ? 3 : 0) + (str_contains($lower, $word) ? 1 : 0);
            }

            return ['block' => $block, 'score' => $score, 'position' => $position];
        }, $blocks, array_keys($blocks));

        usort($scored, fn ($a, $b) => [$b['score'], $a['position']] <=> [$a['score'], $b['position']]);

        return array_column($scored, 'block');
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $matches);

        return array_values(array_unique(array_diff($matches[0], self::STOP_WORDS)));
    }

    private function time(string $value): string
    {
        return Carbon::createFromFormat('H:i', substr($value, 0, 5))->format('g:i A');
    }
}
