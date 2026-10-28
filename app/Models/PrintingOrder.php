<?php

namespace App\Models;

use App\Enums\OptionGroup;
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

    /** The confirmed price as people read it, or null while the shop has not confirmed one. */
    public function confirmedText(): ?string
    {
        return $this->confirmed_price === null ? null : Money::format($this->confirmed_price);
    }

    /** The service name as it was when the order was placed (the catalog may have changed since). */
    public function serviceName(): string
    {
        return $this->snapshot['service']['name'] ?? $this->service?->name ?? 'Printing service';
    }

    /**
     * The options chosen, as they were when the order was placed.
     *
     * @return list<array{group: string, label: string, price: string}>
     */
    public function chosenOptions(): array
    {
        return collect($this->snapshot['options'] ?? [])->map(fn (array $option) => [
            'group' => OptionGroup::tryFrom($option['group'] ?? '')?->label() ?? (string) ($option['group'] ?? ''),
            'label' => (string) ($option['label'] ?? ''),
            'price' => Money::modifier($option['price_modifier'] ?? null),
        ])->values()->all();
    }

    /** Pages count only for a service priced per page; null otherwise. */
    public function pages(): ?int
    {
        return isset($this->snapshot['pages']) ? (int) $this->snapshot['pages'] : null;
    }

    /** The owner asked for more information and the customer has not answered yet. */
    public function needsInfo(): bool
    {
        return $this->info_request !== null && $this->info_request !== '';
    }
}
