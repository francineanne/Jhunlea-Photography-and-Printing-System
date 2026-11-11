<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Enums\BookingStatus;
use App\Enums\RecordSource;
use App\Models\PhotographyBooking;
use App\Models\User;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;

/** Photography sessions in a period: confirmed ones (they hold the time) and, optionally, requests still waiting. */
class BookingsSchedule extends OwnerTool
{
    public function name(): string
    {
        return 'bookings_schedule';
    }

    public function description(): string
    {
        return 'Photography sessions whose time falls in a period (default today), earliest first. status confirmed (default, they hold the time), waiting (requested or proposed) or all.';
    }

    public function parameters(): array
    {
        return self::rangeParameters() + [
            'status' => ['type' => 'string', 'enum' => ['confirmed', 'waiting', 'all'], 'default' => 'confirmed'],
            'source' => ['type' => 'string', 'enum' => ['all', 'online', 'walk_in'], 'default' => 'all', 'description' => 'walk_in: booked at the counter'],
        ];
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $range = $this->range($params);
        $result = new ToolResult($this->name());
        $statuses = match ($params['status']) {
            'waiting' => [BookingStatus::Requested, BookingStatus::Proposed],
            'all' => [BookingStatus::Requested, BookingStatus::Proposed, BookingStatus::Confirmed, BookingStatus::Completed],
            default => [BookingStatus::Confirmed, BookingStatus::Completed],
        };

        $query = PhotographyBooking::query()->whereIn('status', $statuses)
            ->when($range->from, fn (Builder $q, string $day) => $q->where('starts_at', '>=', LocalTime::dayStartUtc($day)))
            ->when($range->to, fn (Builder $q, string $day) => $q->where('starts_at', '<=', LocalTime::dayEndUtc($day)))
            ->when($params['source'] !== 'all', fn (Builder $q) => $q->where('source', RecordSource::from($params['source'])));

        $result->total = (clone $query)->count();

        foreach ((clone $query)->with(['customer:id,name', 'recordedPayment'])->orderBy('starts_at')->orderBy('id')->limit(self::MAX_ROWS)->get() as $booking) {
            $result->rows[] = RecordFacts::booking($booking, $result);
            $result->card($booking->reference_number, route('owner.bookings.show', $booking));
        }

        $result->data = ['period' => $result->label($range->label), 'from' => $result->day($range->from), 'to' => $result->day($range->to),
            'which' => $params['status'], 'source' => $params['source'] === 'all' ? 'online and walk-in' : ($params['source'] === 'walk_in' ? 'walk-in only' : 'online only'),
            'sessions' => $result->count($result->total)];
        $result->link('Schedule', route('owner.schedule.index', array_filter(['view' => 'day', 'date' => $range->from])));

        return $result;
    }
}
