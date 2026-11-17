<?php

namespace App\Support;

use App\Models\ShopSetting;

/**
 * THE one class that reads the shop's business info and every upload limit (Phase 6B). Nothing else reads
 * config('printing.max_*'), config('booking.max_image*') or the shop_settings row.
 *
 * A limit is the owner's saved value when set, otherwise the starting default in config, and never more than what
 * this PHP server accepts (ServerUploadLimits): a value saved earlier that is now above it (for example after a
 * php.ini change) is capped when read, and capped() says so. The row is read once per request (a scoped instance);
 * forget() makes the next read fresh after a save.
 */
class ShopSettings
{
    /**
     * Each owner-editable limit: its config default and what kind of server cap applies to it.
     *
     * @var array<string, array{config: string, cap: 'file'|'total'|'files'}>
     */
    public const LIMITS = [
        'printing_max_file_mb' => ['config' => 'printing.max_file_size_mb', 'cap' => 'file'],
        'printing_max_total_mb' => ['config' => 'printing.max_total_size_mb', 'cap' => 'total'],
        'printing_max_files' => ['config' => 'printing.max_files_per_order', 'cap' => 'files'],
        'booking_max_image_mb' => ['config' => 'booking.max_image_size_mb', 'cap' => 'file'],
        'booking_max_images' => ['config' => 'booking.max_images_per_booking', 'cap' => 'files'],
        'booking_max_total_mb' => ['config' => 'booking.max_total_size_mb', 'cap' => 'total'],
    ];

    // opening_hours (Phase 10A): the shop's own opening hours as display text; the photoshoot hours are availability_rules
    // facebook_url (Phase 10D): the shop's Facebook page, an https address on facebook.com or fb.com
    public const BUSINESS = ['shop_name', 'address', 'phone', 'email', 'opening_hours', 'facebook_url'];

    private ?ShopSetting $row = null;

    private bool $loaded = false;

    public function __construct(private readonly ServerUploadLimits $server) {}

    public function forget(): void
    {
        $this->row = null;
        $this->loaded = false;
    }

    // Business info: null when not set, never a placeholder

    public function shopName(): ?string
    {
        return $this->text('shop_name');
    }

    public function address(): ?string
    {
        return $this->text('address');
    }

    public function phone(): ?string
    {
        return $this->text('phone');
    }

    public function email(): ?string
    {
        return $this->text('email');
    }

    /** The shop's opening hours as the owner typed them ("Mon-Sat 9:00 AM - 5:00 PM; Sun closed"); null when not set. */
    public function openingHours(): ?string
    {
        return $this->text('opening_hours');
    }

    /** Phase 10E: the owner's Lumi switch (Settings, Lumi assistant); not set means on. */
    public function lumiEnabled(): bool
    {
        $value = $this->row()?->getAttribute('lumi_enabled');

        return $value === null || (bool) $value;
    }

    /** Phase 10E: what customers read in Ask Lumi when Lumi cannot answer; null means the starting text. */
    public function lumiFallbackText(): ?string
    {
        return $this->text('lumi_fallback_text');
    }

    /** Phase 10D: the shop's Facebook page address; null when not set. */
    public function facebookUrl(): ?string
    {
        return $this->text('facebook_url');
    }

    /**
     * Phase 10D: the Google Maps addresses made from the shop's address (no key, no account): the embedded map of the
     * landing page and the "Open in Google Maps" link. Null when no address is set.
     *
     * @return array{embed: string, link: string}|null
     */
    public function map(): ?array
    {
        $address = $this->address();

        if ($address === null) {
            return null;
        }

        $query = rawurlencode(trim((string) preg_replace('/\s+/', ' ', $address)));

        return ['embed' => "https://www.google.com/maps?q={$query}&output=embed", 'link' => "https://www.google.com/maps/search/?api=1&query={$query}"];
    }

    /** @return array{shop_name: ?string, address: ?string, phone: ?string, email: ?string, opening_hours: ?string, facebook_url: ?string} */
    public function business(): array
    {
        return ['shop_name' => $this->shopName(), 'address' => $this->address(), 'phone' => $this->phone(), 'email' => $this->email(), 'opening_hours' => $this->openingHours(), 'facebook_url' => $this->facebookUrl()];
    }

    /** True when the public site has at least one way to reach the shop to show. */
    public function hasContact(): bool
    {
        return $this->address() !== null || $this->phone() !== null || $this->email() !== null || $this->openingHours() !== null;
    }

    // Payment rules (Phase 10B): the owner's saved value, else the config default, always inside config payments.limits

    /** The whole-number rules (percent, days, hours); prepay_from is money and read with prepayFrom(). */
    public const PAYMENT_RULES = ['downpayment_percent', 'downpayment_due_days', 'downpayment_min_hours', 'unclaimed_after_days'];

    public const GCASH = ['gcash_name', 'gcash_number'];

    public function paymentRule(string $field): int
    {
        $value = $this->row()?->getAttribute($field);
        [$min, $max] = config("payments.limits.{$field}");

        return (int) max($min, min($max, $value === null ? (int) config("payments.defaults.{$field}") : (int) $value));
    }

    public function storedPaymentRule(string $field): int|string|null
    {
        return $this->row()?->getAttribute($field);
    }

    public function downpaymentPercent(): int
    {
        return $this->paymentRule('downpayment_percent');
    }

    /** The confirmed price from which a printing order is paid before printing, as "300.00". */
    public function prepayFrom(): string
    {
        $value = $this->row()?->getAttribute('prepay_from');

        return Money::fromCents(max(0, Money::toCents($value === null ? (string) config('payments.defaults.prepay_from') : (string) $value)));
    }

    public function gcashName(): ?string
    {
        return $this->text('gcash_name');
    }

    public function gcashNumber(): ?string
    {
        return $this->text('gcash_number');
    }

    // Upload limits: always the effective value

    public function printingMaxFileMb(): int
    {
        // A file can never be bigger than all files of the order together
        return min($this->limit('printing_max_file_mb'), $this->printingMaxTotalMb());
    }

    public function printingMaxTotalMb(): int
    {
        return $this->limit('printing_max_total_mb');
    }

    public function printingMaxFiles(): int
    {
        return $this->limit('printing_max_files');
    }

    public function bookingMaxImageMb(): int
    {
        return min($this->limit('booking_max_image_mb'), $this->bookingMaxTotalMb());
    }

    public function bookingMaxImages(): int
    {
        return $this->limit('booking_max_images');
    }

    public function bookingMaxTotalMb(): int
    {
        return $this->limit('booking_max_total_mb');
    }

    /** The starting default of a limit (config), itself capped by the server. */
    public function defaultFor(string $field): int
    {
        return min((int) config(self::LIMITS[$field]['config']), $this->serverMax($field));
    }

    /** The owner's saved value of a limit, or null when the starting default is used. */
    public function storedFor(string $field): ?int
    {
        $value = $this->row()?->getAttribute($field);

        return $value === null ? null : (int) $value;
    }

    /** The most this server accepts for a limit: the highest value the Settings form allows. */
    public function serverMax(string $field): int
    {
        return match (self::LIMITS[$field]['cap']) {
            'file' => $this->server->maxFileMb(),
            'total' => $this->server->maxTotalMb(),
            'files' => $this->server->maxFiles(),
        };
    }

    /** True when the saved (or default) value is above what the server accepts, so a lower value is used. */
    public function capped(string $field): bool
    {
        return ($this->storedFor($field) ?? (int) config(self::LIMITS[$field]['config'])) > $this->serverMax($field);
    }

    public function server(): ServerUploadLimits
    {
        return $this->server;
    }

    private function limit(string $field): int
    {
        return max(1, min($this->storedFor($field) ?? (int) config(self::LIMITS[$field]['config']), $this->serverMax($field)));
    }

    private function text(string $field): ?string
    {
        $value = $this->row()?->getAttribute($field);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function row(): ?ShopSetting
    {
        if (! $this->loaded) {
            // rescue: the public layout also wraps the error pages, and a database that is down or not migrated yet
            // must never break a page; the defaults are used then
            $this->row = rescue(fn () => ShopSetting::query()->find(ShopSetting::ROW_ID), null, false);
            $this->loaded = true;
        }

        return $this->row;
    }
}
