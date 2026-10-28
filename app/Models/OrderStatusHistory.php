<?php

namespace App\Models;

use App\Enums\OrderEvent;
use App\Enums\PrintingOrderStatus;
use Database\Factories\OrderStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Written once by StatusTransitionService and never edited, so it has no updated_at and nothing is fillable.
class OrderStatusHistory extends Model
{
    /** @use HasFactory<OrderStatusHistoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => PrintingOrderStatus::class,
            'to_status' => PrintingOrderStatus::class,
            'event' => OrderEvent::class,
        ];
    }

    /** What happened, in a few words: the event, the first row of the order, or the status it moved to. */
    public function title(): string
    {
        return match (true) {
            $this->event !== null => $this->event->label(),
            $this->from_status === null => 'Order placed',
            default => $this->to_status->label(),
        };
    }

    /** @return BelongsTo<PrintingOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PrintingOrder::class, 'printing_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
