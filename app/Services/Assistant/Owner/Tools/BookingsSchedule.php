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

/**
 * Photography sessions in a period. Phase 10T: the default ("holding") is every booking that holds its time: Awaiting
 * downpayment, Confirmed and Completed (an awaiting one already holds its time on the schedule), each row with its
 * status and, when awaiting, its downpayment and deadline. "waiting" lists the requests still waiting for an answer
 * (never an expired one); "all" is both. "confirmed" is the old name of the default and is still accepted.
 */
class BookingsSchedule extends OwnerTool
{
    private const HOLDING = [BookingStatus::AwaitingDownpayment, BookingStatus::Confirmed, BookingStatus::Completed];

    private const WAITING = [BookingStatus::Requested, BookingStatus::Proposed];

    public function name(): string
    {
        return 'bookings_schedule';
    }

    public function description(): string
    {
        return 'Photography sessions whose time falls in a period (default today), earliest first. status holding (default): every session that holds its time (awaiting downpayment, confirmed, completed); say how many are awaiting their downpayment and name them with the amount and deadline, because they are not paid yet. waiting: requests still waiting for an answer (requested or proposed). all: both. data gives the split (confirmed, awaiting_downpayment, completed) and waiting_requests for the same period.';
    }

    public function parameters(): array
    {
        return self::rangeParameters() + [
            // "confirmed" is the pre-10T name of "holding", kept so older plans still work
            'status' => ['type' => 'string', 'enum' => ['holding', 'confirmed', 'waiting', 'all'], 'default' => 'holding'],
            'source' => ['type' => 'string', 'enum' => ['all', 'online', 'walk_in'], 'default' => 'all', 'description' => 'walk_in: booked at the counter'],
        ];
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $range = $this->range($params);
        $result = new ToolResult($this->name());
        $which = $params['status'] === 'confirmed' ? 'holding' : $params['status'];

        $inPeriod = fn (): Builder => PhotographyBooking::query()
            ->when($range->from, fn (Builder $q, string $day) => $q->where('starts_at', '>=', LocalTime::dayStartUtc($day)))
            ->when($range->to, fn (Builder $q, string $day) => $q->where('starts_at', '<=', LocalTime::dayEndUtc($day)))
            ->when($params['source'] !== 'all', fn (Builder $q) => $q->where('source', RecordSource::from($params['source'])));

        // Phase 10L: an expired request (its time passed without an answer) is not waiting, and is never listed here
        $waiting = fn (): Builder => $inPeriod()->whereIn('status', self::WAITING)->waiting();
        $holding = fn (): Builder => $inPeriod()->whereIn('status', self::HOLDING);

        $query = match ($which) {
            'waiting' => $waiting(),
            'all' => $inPeriod()->where(fn (Builder $q) => $q->whereIn('status', self::HOLDING)
                ->orWhere(fn (Builder $open) => $open->whereIn('status', self::WAITING)->waiting())),
            default => $holding(),
        };

        $result->total = (clone $query)->count();

        foreach ((clone $query)->with(['customer:id,name', 'recordedPayment'])->orderBy('starts_at')->orderBy('id')->limit(self::MAX_ROWS)->get() as $booking) {
            $result->rows[] = RecordFacts::booking($booking, $result);
            $result->card($booking->reference_number, route('owner.bookings.show', $booking));
        }

        $split = $holding()->toBase()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $count = fn (BookingStatus $status): int => $result->count((int) ($split[$status->value] ?? 0));

        $result->data = ['period' => $result->label($range->label), 'from' => $result->day($range->from), 'to' => $result->day($range->to),
            'which' => match ($which) {
                'waiting' => 'requests waiting for an answer',
                'all' => 'sessions that hold their time and requests waiting for an answer',
                default => 'sessions that hold their time (awaiting downpayment, confirmed, completed)',
            },
            'source' => $params['source'] === 'all' ? 'online and walk-in' : ($params['source'] === 'walk_in' ? 'walk-in only' : 'online only'),
            'sessions' => $result->count($result->total),
            'confirmed' => $count(BookingStatus::Confirmed),
            'awaiting_downpayment' => $count(BookingStatus::AwaitingDownpayment),
            'completed' => $count(BookingStatus::Completed),
            'waiting_requests' => $result->count($waiting()->count()),
        ];
        $result->link('Schedule', route('owner.schedule.index', array_filter(['view' => 'day', 'date' => $range->from])));

        return $result;
    }
}
