<?php

namespace App\Models;

use Database\Factories\OrderFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Nothing is fillable: a file row is created only by OrderFileService, from a file it has already checked and stored.
class OrderFile extends Model
{
    /** @use HasFactory<OrderFileFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    /** The size as people read it: 820 KB, 3.4 MB. */
    public function sizeText(): string
    {
        return $this->size_bytes >= 1024 * 1024
            ? number_format($this->size_bytes / 1024 / 1024, 1).' MB'
            : max(1, (int) round($this->size_bytes / 1024)).' KB';
    }

    /** @return BelongsTo<PrintingOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PrintingOrder::class, 'printing_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
