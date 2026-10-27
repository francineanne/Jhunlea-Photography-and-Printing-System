<?php

namespace App\Models;

use Database\Factories\ThemeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

// image_path is not fillable: it is set only by the upload code, never copied from a request
#[Fillable(['name', 'description', 'color_swatches', 'is_published'])]
class Theme extends Model
{
    /** @use HasFactory<ThemeFactory> */
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'color_swatches' => 'array',
            'is_published' => 'boolean',
        ];
    }

    /** @return BelongsToMany<PhotographyPackage, $this> */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(PhotographyPackage::class, 'package_theme')->orderBy('photography_packages.name');
    }

    /** @param Builder<Theme> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk(PackageImage::DISK)->url($this->image_path) : null;
    }
}
