<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Enums\PrintingOrderStatus;
use App\Enums\RecordSource;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;

/** Printing orders placed in a period, per status, and the latest ones (optionally one status only). */
class OrdersOverview extends OwnerTool
{
    public function name(): string
    {
        return 'orders_overview';
    }

    public function description(): string
    {
        return 'Printing orders placed in a period: how many per status, how many wait for review, and the latest ones. Use status to list only one status (any period: range all_time).';
    }

    public function parameters(): array
    {
        return self::rangeParameters('all_time') + [
            'status' => ['type' => 'string', 'enum' => array_map(fn (PrintingOrderStatus $status) => $status->value, PrintingOrderStatus::cases())],
            'source' => ['type' => 'string', 'enum' => ['all', 'online', 'walk_in'], 'default' => 'all', 'description' => 'walk_in: made at the counter'],
        ];
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $range = $this->range($params, 'all_time');
        $result = new ToolResult($this->name());
        $period = PrintingOrder::query()
            ->when($range->from, fn (Builder $query, string $day) => $query->where('created_at', '>=', LocalTime::dayStartUtc($day)))
            ->when($range->to, fn (Builder $query, string $day) => $query->where('created_at', '<=', LocalTime::dayEndUtc($day)))
            ->when($params['source'] !== 'all', fn (Builder $query) => $query->where('source', RecordSource::from($params['source'])));

        $counts = (clone $period)->toBase()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $perStatus = [];

        foreach (PrintingOrderStatus::cases() as $status) {
            $perStatus[$status->label()] = $result->count((int) ($counts[$status->value] ?? 0));
        }

        $listed = (clone $period)->when($params['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status));
        $result->total = (clone $listed)->count();

        foreach ((clone $listed)->with(['customer:id,name', 'recordedPayment'])->latest()->latest('id')->limit(self::MAX_ROWS)->get() as $order) {
            $result->rows[] = RecordFacts::order($order, $result);
            $result->card($order->reference_number, route('owner.orders.show', $order));
        }

        $result->data = [
            'period' => $result->label($range->label),
            'from' => $result->day($range->from),
            'to' => $result->day($range->to),
            'orders' => $result->count(array_sum($perStatus)),
            'per_status' => $perStatus,
            'waiting_for_review' => $result->count(($perStatus[PrintingOrderStatus::Submitted->label()] ?? 0) + ($perStatus[PrintingOrderStatus::UnderReview->label()] ?? 0)),
            'listed_status' => isset($params['status']) ? PrintingOrderStatus::from($params['status'])->label() : 'all',
            'source' => $params['source'] === 'all' ? 'online and walk-in' : ($params['source'] === 'walk_in' ? 'walk-in only' : 'online only'),
        ];
        $result->link('Printing orders', route('owner.orders.index', $params['source'] === 'all' ? [] : ['source' => $params['source']]));

        return $result;
    }
}
