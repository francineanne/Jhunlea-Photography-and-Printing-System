<?php

namespace App\Models;

use App\Enums\PrintingOrderStatus;
use App\Support\Money;
use Database\Factories\PrintingOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Only what a customer types is fillable. The owner of the order, the status, the reference number and every price
// are set by server code (PrintingOrderService, StatusTransitionService), never from a request.
#[Fillable(['quantity', 'notes'])]
class PrintingOrder extends Model
{
    /** @use HasFactory<PrintingOrderFactory> */
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PrintingOrderStatus::class,
            'snapshot' => 'array',
            'quantity' => 'integer',
            'estimated_price' => 'decimal:2',
            'confirmed_price' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * An order keeps its service even after the owner removes it from the catalog.
     *
     * @return BelongsTo<PrintingService, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(PrintingService::class, 'printing_service_id')->withTrashed();
    }

    /** @return HasMany<OrderFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(OrderFile::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /** The estimate as customers read it. No estimate means the shop quotes the price after reviewing the request. */
    public function estimateText(): string
    {
        return $this->estimated_price === null ? 'Price quoted after review' : Money::format($this->estimated_price);
    }

    /** The owner asked for more information and the customer has not answered yet. */
    public function needsInfo(): bool
    {
        return $this->info_request !== null && $this->info_request !== '';
    }
}
