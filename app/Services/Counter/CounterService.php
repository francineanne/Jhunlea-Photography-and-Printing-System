<?php

namespace App\Services\Counter;

use App\Enums\PaymentStatus;
use App\Enums\PrintingOrderStatus;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Services\PaymentLedgerService;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The shop counter on the Payments page (Phase 8B), read side only: one search box over orders and bookings, the plain
 * answer to "can this be paid now, and if not why", and the two queues (ready for pickup, awaiting payment). Recording
 * itself always goes through PaymentService, so every Phase 5 rule is unchanged. "Awaiting payment" is the ledger's own
 * rule (PaymentLedgerService::awaitingOrders()/awaitingBookings()).
 */
class CounterService
{
    public const SEARCH_LIMIT = 8;

    public const PER_PAGE = 10;

    public function __construct(private readonly PaymentLedgerService $ledger) {}

    /**
     * Orders and bookings by reference (PR-/PB-, with or without the hyphen), customer name or phone, or the booking's
     * contact number. % _ \ are matched literally. Newest first, at most SEARCH_LIMIT of each kind.
     *
     * @return Collection<int, PrintingOrder|PhotographyBooking>
     */
    public function search(string $term): Collection
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        // "pr123" or "PR 000123" still finds PR-000123
        $compact = preg_replace('/^(P[RB])[\s-]*/i', '$1-', $term);
        $refLike = '%'.addcslashes((string) $compact, '%_\\').'%';

        // Grouped, so the OR stays inside the customer match and never next to the relation's own join condition
        $people = fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->where('name', 'like', $like)->orWhere('phone', 'like', $like));

        $orders = PrintingOrder::query()->with(['customer:id,name,phone,is_walk_in', 'recordedPayment'])
            ->where(fn (Builder $query) => $query->where('reference_number', 'like', $refLike)->orWhereHas('customer', $people))
            ->latest('id')->limit(self::SEARCH_LIMIT)->get();

        $bookings = PhotographyBooking::query()->with(['customer:id,name,phone,is_walk_in', 'recordedPayment'])
            ->where(fn (Builder $query) => $query->where('reference_number', 'like', $refLike)->orWhere('contact_number', 'like', $like)->orWhereHas('customer', $people))
            ->latest('id')->limit(self::SEARCH_LIMIT)->get();

        return $orders->concat($bookings)->sortByDesc(fn ($record) => $record->created_at?->getTimestamp())->values();
    }

    /** The order or booking a counter form names (`printing_order` or `photography_booking` and an id), or null. */
    public function find(string $type, int $id): PrintingOrder|PhotographyBooking|null
    {
        $query = match ($type) {
            'printing_order' => PrintingOrder::query(),
            'photography_booking' => PhotographyBooking::query(),
            default => null,
        };

        return $query?->with(['customer:id,name,phone,is_walk_in', 'recordedPayment'])->find($id);
    }

    /**
     * Why this cannot be paid at the counter right now, in plain words, or null when it can (the same answer as
     * paymentCanBeRecorded(), worded for the owner).
     */
    public function blocker(PrintingOrder|PhotographyBooking $record): ?string
    {
        $isOrder = $record instanceof PrintingOrder;
        $what = $isOrder ? 'order' : 'booking';

        return match (true) {
            $record->status->isClosed() => 'This '.$what.' is '.strtolower($record->status->label()).', so there is nothing to pay.',
            $record->recordedPayment !== null => 'Already paid ('.$record->recordedPayment->reference_number.', '.Money::format($record->recordedPayment->amount).').',
            $record->confirmed_price === null => 'No confirmed price yet. Confirm the price first; the payment is always the full confirmed price.',
            $record->paymentStatus() === PaymentStatus::NoCharge => 'Nothing to pay: the confirmed price is ₱0.00.',
            ! $record->status->takesPayment() => $isOrder ? 'Accept the order with its price first.' : 'Confirm the booking first.',
            ! $record->paymentCanBeRecorded() => 'A payment cannot be recorded for this '.$what.' right now.',
            default => null,
        };
    }

    /**
     * What the counter shows for one order or booking (search results and the record-payment modal).
     *
     * @return array<string, mixed>
     */
    public function describe(PrintingOrder|PhotographyBooking $record): array
    {
        $isOrder = $record instanceof PrintingOrder;
        $blocker = $this->blocker($record);

        return [
            'type' => $record->getMorphClass(),
            'id' => (int) $record->getKey(),
            'reference' => (string) $record->reference_number,
            'customer' => (string) ($record->customer?->name ?? 'Deleted account'),
            'phone' => $record->customer?->phone,
            'what' => $isOrder ? $record->serviceName() : (string) ($record->snapshot['package']['name'] ?? 'Photoshoot'),
            'kind' => $isOrder ? 'Printing order' : 'Photoshoot',
            'walk_in' => $record->isWalkIn(),
            'status' => $record->status->label(),
            'price' => $record->confirmed_price,
            'price_text' => $record->confirmed_price !== null ? Money::format($record->confirmed_price) : 'No confirmed price',
            'paid_text' => $record->recordedPayment ? Money::format($record->recordedPayment->amount) : Money::format('0'),
            'payment' => $record->paymentStatus()->label(),
            'payable' => $blocker === null,
            'blocker' => $blocker,
            'when' => $isOrder ? null : LocalTime::format($record->starts_at, 'D, M j g:i A'),
        ];
    }

    /**
     * Printing orders ready for pickup, the oldest first, paid or not.
     *
     * @return LengthAwarePaginator<int, PrintingOrder>
     */
    public function readyForPickup(): LengthAwarePaginator
    {
        return PrintingOrder::query()->with(['customer:id,name,phone,is_walk_in', 'recordedPayment'])
            ->where('status', PrintingOrderStatus::ReadyForPickup)
            ->oldest('updated_at')->oldest('id')
            ->paginate(self::PER_PAGE, pageName: 'ready')->withQueryString();
    }

    public function readyCount(): int
    {
        return PrintingOrder::query()->where('status', PrintingOrderStatus::ReadyForPickup)->count();
    }

    /**
     * Orders and bookings awaiting payment (the ledger's rule), the longest waiting first: one UNION of the two
     * awaiting queries sorted and paged by the database, then each kind loaded in one query.
     *
     * @return LengthAwarePaginator<int, PrintingOrder|PhotographyBooking>
     */
    public function awaitingPayment(): LengthAwarePaginator
    {
        $orders = $this->ledger->awaitingOrders()->toBase()->select([DB::raw("'printing_order' as kind"), 'id', 'created_at']);
        $bookings = $this->ledger->awaitingBookings()->toBase()->select([DB::raw("'photography_booking' as kind"), 'id', 'created_at']);

        $page = DB::query()->fromSub($orders->unionAll($bookings), 'awaiting')
            ->orderBy('created_at')->orderBy('kind')->orderBy('id')
            ->paginate(self::PER_PAGE, pageName: 'awaiting')->withQueryString();

        $rows = $page->getCollection();
        $orderRows = PrintingOrder::query()->with(['customer:id,name,phone,is_walk_in', 'recordedPayment'])->whereKey($rows->where('kind', 'printing_order')->pluck('id')->all())->get()->keyBy('id');
        $bookingRows = PhotographyBooking::query()->with(['customer:id,name,phone,is_walk_in', 'recordedPayment'])->whereKey($rows->where('kind', 'photography_booking')->pluck('id')->all())->get()->keyBy('id');

        return $page->setCollection($rows->map(fn ($row) => $row->kind === 'printing_order' ? $orderRows->get($row->id) : $bookingRows->get($row->id))->filter()->values());
    }

    public function awaitingCount(): int
    {
        return $this->ledger->awaitingOrders()->count() + $this->ledger->awaitingBookings()->count();
    }

    /** Whole Manila calendar days since the request came in (0 = today). */
    public static function daysWaiting(PrintingOrder|PhotographyBooking $record): int
    {
        $today = LocalTime::in(Carbon::now('UTC'))->startOfDay();

        return (int) max(0, LocalTime::in($record->created_at)->startOfDay()->diffInDays($today));
    }
}
