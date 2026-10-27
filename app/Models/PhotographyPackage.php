<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\PhotographyPackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'category', 'short_description', 'description', 'price', 'price_requires_confirmation', 'duration_minutes', 'max_people', 'digital_outputs', 'inclusions', 'terms', 'is_published', 'is_featured', 'sort_order'])]
class PhotographyPackage extends Model
{
    /** @use HasFactory<PhotographyPackageFactory> */
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Kept as a string like "1850.00" so money never goes through a float
            'price' => 'decimal:2',
            'price_requires_confirmation' => 'boolean',
            'duration_minutes' => 'integer',
            'max_people' => 'integer',
            'digital_outputs' => 'integer',
            'inclusions' => 'array',
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<PackageImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(PackageImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasOne<PackageImage, $this> */
    public function coverImage(): HasOne
    {
        return $this->hasOne(PackageImage::class)->where('is_cover', true);
    }

    /** @return BelongsToMany<Theme, $this> */
    public function themes(): BelongsToMany
    {
        return $this->belongsToMany(Theme::class, 'package_theme')->orderBy('themes.name');
    }

    /** @param Builder<PhotographyPackage> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /** @param Builder<PhotographyPackage> $query */
    public function scopeFeatured(Builder $query): void
    {
        $query->where('is_featured', true);
    }

    /**
     * The price as customers read it. The one place that decides the wording, so lists, previews and later pages agree:
     * a price that the owner still confirms, a plain price, or no price at all.
     */
    public function priceText(): string
    {
        if ($this->price === null) {
            return 'Price on request';
        }

        $price = Money::format($this->price);

        return $this->price_requires_confirmation ? "From {$price} — final price confirmed after review" : $price;
    }

    /**
     * A slug from the given text that no other package uses. Soft-deleted packages still hold their slug
     * (the database index covers them), so they are counted here too.
     */
    public static function uniqueSlug(string $text, ?int $ignoreId = null): string
    {
        $base = Str::slug($text) ?: 'package';
        $slug = $base;
        $number = 2;

        while (static::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$number++;
        }

        return $slug;
    }
}
