<?php

namespace App\Models;

use App\Enums\PricingType;
use App\Support\Money;
use Database\Factories\PrintingServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'description', 'category', 'pricing_type', 'base_price', 'allowed_file_types', 'max_file_size_mb', 'instructions', 'is_published', 'sort_order'])]
class PrintingService extends Model
{
    /** @use HasFactory<PrintingServiceFactory> */
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pricing_type' => PricingType::class,
            // Kept as a string like "12.50" so money never goes through a float
            'base_price' => 'decimal:2',
            'allowed_file_types' => 'array',
            'max_file_size_mb' => 'integer',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<PrintingServiceOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(PrintingServiceOption::class);
    }

    /**
     * The price as customers read it. The base price is what the service starts from: the options a customer picks
     * (paper size, finish and so on) may add to it.
     */
    public function priceText(): string
    {
        $price = $this->base_price === null ? null : Money::format($this->base_price);

        return match (true) {
            $this->pricing_type === PricingType::Quotation => $price ? "From {$price} — price quoted after review" : 'Price quoted after review',
            $price === null => 'Price on request',
            $this->pricing_type === PricingType::PerPage => "From {$price} per page",
            default => "From {$price} per copy",
        };
    }

    /** @param Builder<PrintingService> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * A slug from the given text that no other service uses. Soft-deleted services still hold their slug
     * (the database index covers them), so they are counted here too.
     */
    public static function uniqueSlug(string $text, ?int $ignoreId = null): string
    {
        $base = Str::slug($text) ?: 'service';
        $slug = $base;
        $number = 2;

        while (static::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$number++;
        }

        return $slug;
    }
}
