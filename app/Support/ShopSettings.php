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

    public const BUSINESS = ['shop_name', 'address', 'phone', 'email'];

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

    /** @return array{shop_name: ?string, address: ?string, phone: ?string, email: ?string} */
    public function business(): array
    {
        return ['shop_name' => $this->shopName(), 'address' => $this->address(), 'phone' => $this->phone(), 'email' => $this->email()];
    }

    /** True when the public site has at least one way to reach the shop to show. */
    public function hasContact(): bool
    {
        return $this->address() !== null || $this->phone() !== null || $this->email() !== null;
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
