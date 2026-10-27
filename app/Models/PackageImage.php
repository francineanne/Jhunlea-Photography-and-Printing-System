<?php

namespace App\Models;

use Database\Factories\PackageImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

// Only the alt text comes from a form. The package comes from the route; path, cover flag and order are set by the server.
#[Fillable(['alt_text'])]
class PackageImage extends Model
{
    /** @use HasFactory<PackageImageFactory> */
    use HasFactory;

    /** Catalog photos are public marketing content, so they live on the public disk. */
    public const DISK = 'public';

    public const MAX_PER_PACKAGE = 12;

    public const MAX_PER_REQUEST = 10;

    public const MAX_SIZE_KB = 5120;

    /** A small file can still decode to a huge picture, which can exhaust a browser's memory */
    public const MAX_PIXELS = 8000;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_cover' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<PhotographyPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(PhotographyPackage::class, 'photography_package_id');
    }

    public function url(): string
    {
        return Storage::disk(self::DISK)->url($this->path);
    }
}
