<?php

namespace App\Http\Controllers\Owner;

use App\Enums\BookingStatus;
use App\Enums\OptionGroup;
use App\Enums\PaymentMethod;
use App\Enums\PricingType;
use App\Exceptions\BookingTransitionException;
use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\WalkIn\StoreWalkInBookingRequest;
use App\Http\Requests\Owner\WalkIn\StoreWalkInOrderRequest;
use App\Http\Requests\Owner\WalkIn\WalkInLookupRequest;
use App\Http\Requests\Owner\WalkIn\WalkInRequest;
use App\Models\BookingSetting;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\Theme;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\BookingTransitionService;
use App\Services\ReferenceNumberService;
use App\Services\WalkIn\CounterRecordAlreadySaved;
use App\Services\WalkIn\WalkInBookingService;
use App\Services\WalkIn\WalkInCustomerService;
use App\Services\WalkIn\WalkInOrderService;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\ShopSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * The owner's counter (Phase 8A): walk-in printing orders (paid at creation) and walk-in photography bookings. The
 * work is done by WalkInOrderService and WalkInBookingService; the customer, when new, is created in the same
 * transaction, so a refused order or booking leaves no stray walk-in customer behind.
 */
class WalkInController extends Controller
{
    public function __construct(private readonly WalkInCustomerService $customers) {}

    /** Customers by name or phone, for the picker (JSON). */
    public function customers(WalkInLookupRequest $request): JsonResponse
    {
        return response()->json(['customers' => $this->customers->search((string) $request->validated('q'))->map(fn (User $customer) => [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'walk_in' => $customer->isWalkIn(),
        ])->values()]);
    }

    public function createOrder(): View
    {
        $services = PrintingService::published()->with(['options' => fn ($query) => $query->active()->orderBy('id')])->orderBy('sort_order')->orderBy('name')->get();

        return view('owner.walk-in.order', [
            'services' => $services,
            'groups' => OptionGroup::cases(),
            'perPage' => $services->filter(fn (PrintingService $service) => $service->pricing_type === PricingType::PerPage)->pluck('id')->values(),
            'methods' => PaymentMethod::cases(),
            'token' => (string) Str::uuid(),
            'picked' => $this->picked(),
        ]);
    }

    public function storeOrder(StoreWalkInOrderRequest $request, WalkInOrderService $orders): RedirectResponse
    {
        $service = PrintingService::query()->find((int) $request->validated('service'));

        if ($service === null) {
            return $this->refuse(route('owner.orders.walk-in.create'), ['service' => 'Choose a printing service.']);
        }

        try {
            $order = DB::transaction(function () use ($request, $orders, $service) {
                // The payment number is locked before a new walk-in customer writes its audit row (see place(), 9B)
                app(ReferenceNumberService::class)->lock(ReferenceNumberService::PAYMENT);

                $result = $orders->place(
                    $request->user(),
                    $this->customer($request),
                    $service,
                    $request->optionIds(),
                    (int) $request->validated('quantity'),
                    (int) ($request->validated('pages') ?? 1),
                    $request->validated('notes'),
                    (string) $request->validated('price_choice'),
                    $request->validated('custom_price'),
                    $request->validated('price_note'),
                    $request->paymentMethod(),
                    $request->validated('gcash_reference'),
                    $request->counterToken(),
                    $request->uploads(),
                );

                return $result['created'] ? $result['order'] : throw new CounterRecordAlreadySaved($result['order']);
            });
        } catch (CounterRecordAlreadySaved $saved) {
            $order = $saved->record;
        } catch (ValidationException $e) {
            return $this->refuse(route('owner.orders.walk-in.create'), $e->errors());
        } catch (PaymentException|InvalidArgumentException $e) {
            return $this->refuse(route('owner.orders.walk-in.create'), ['payment' => $e->getMessage()]);
        }

        $tendered = $request->paymentMethod() !== null ? $request->validated('cash_tendered') : null;
        $change = $tendered !== null && $request->paymentMethod() === PaymentMethod::Cash && $order->confirmed_price !== null
            ? max(0, Money::toCents($tendered) - Money::toCents($order->confirmed_price))
            : null;

        return redirect()->route('owner.orders.walk-in.done', $order)->with('change_cents', $change);
    }

    /** The success screen of a walk-in order (the printable slip comes in Phase 8B). */
    public function orderDone(PrintingOrder $order): View
    {
        abort_unless($order->isWalkIn(), 404);
        $order->load(['customer', 'recordedPayment']);

        return view('owner.walk-in.order-done', ['order' => $order, 'changeCents' => session('change_cents')]);
    }

    public function createBooking(): View
    {
        $packages = PhotographyPackage::query()->where('is_published', true)
            ->with(['themes' => fn ($query) => $query->where('is_published', true)->orderBy('name')])
            ->orderBy('name')->get();

        return view('owner.walk-in.booking', [
            'packages' => $packages,
            'today' => LocalTime::format(now(), 'Y-m-d'),
            // Phase 10B: with a downpayment rule the form takes the downpayment (or the full price) now, or a waiver
            'downpaymentPercent' => app(ShopSettings::class)->downpaymentPercent(),
            // Phase 10A: who each package is for, so the people field starts at the minimum and stays in range
            'people' => $packages->mapWithKeys(fn (PhotographyPackage $package) => [$package->id => [
                'min' => $package->minPeople(), 'max' => $package->max_people, 'rule' => BookingService::groupSizeMessage($package),
            ]]),
            'token' => (string) Str::uuid(),
            'picked' => $this->picked(),
            // Phase 10J: the same schedule rules as online, worded once for the form
            'windowText' => BookingSetting::current()->windowText(),
        ]);
    }

    /** Phase 10J: the free start times of a package on a date, by the ONLINE rules (the same slots the wizard offers). JSON. */
    public function slots(WalkInLookupRequest $request, AvailabilityService $availability): JsonResponse
    {
        $package = PhotographyPackage::query()->where('is_published', true)->find((int) $request->validated('package'));
        $date = $request->validated('date');

        if ($package === null || $date === null) {
            return response()->json(['times' => [], 'over_limit' => false, 'day_text' => null]);
        }

        $day = $availability->overview($package->duration_minutes, $date, $date)->first();

        return response()->json([
            'times' => collect($day['slots'])
                ->map(fn ($slot) => ['value' => LocalTime::format($slot->startsAt, 'H:i'), 'label' => LocalTime::format($slot->startsAt, 'g:i A'), 'ends' => LocalTime::format($slot->endsAt, 'g:i A')])
                ->values(),
            // Phase 10J: no "book anyway" at the counter; the day is simply full
            'over_limit' => $day['overLimit'],
            'day_text' => $day['slots'] === [] ? AvailabilityService::dayText($day['state']) : null,
        ]);
    }

    public function storeBooking(StoreWalkInBookingRequest $request, WalkInBookingService $bookings): RedirectResponse
    {
        $package = PhotographyPackage::query()->find((int) $request->validated('package'));
        $theme = $request->validated('theme') ? Theme::query()->where('is_published', true)->find((int) $request->validated('theme')) : null;

        if ($package === null) {
            return $this->refuse(route('owner.bookings.walk-in.create'), ['package' => 'Choose a package.']);
        }

        if ($request->validated('theme') && $theme === null) {
            return $this->refuse(route('owner.bookings.walk-in.create'), ['theme' => 'This backdrop is not available for this package. Please choose another one.']);
        }

        try {
            $booking = DB::transaction(function () use ($request, $bookings, $package, $theme) {
                // Lock order (Phase 10A): the schedule lock is the FIRST lock of every booking transaction. A new
                // walk-in customer writes an audit row (the chain head lock), and confirm() takes the schedule lock
                // before the head, so the schedule lock must be taken before the customer is created
                app(BookingTransitionService::class)->lockSchedule();
                // Phase 10B: a payment may be recorded below, so the PAY counter is locked before the audit head (the
                // new customer's audit row), the same order as every other payment (docs/MYSQL_TESTING.md)
                app(ReferenceNumberService::class)->lock(ReferenceNumberService::PAYMENT);

                $customer = $this->customer($request);
                // Phase 10J: ONE phone, the customer's own unless the owner typed a different one for this booking
                $contact = $request->contactFor($customer) ?? throw ValidationException::withMessages(['contact_number' => StoreWalkInBookingRequest::NO_PHONE]);
                $this->customers->fillPhoneIfMissing($customer, $contact);

                $result = $bookings->place(
                    $request->user(),
                    $customer,
                    $package,
                    $theme,
                    $request->validated('custom_theme_text'),
                    $request->startsAt(),
                    (int) $request->validated('group_size'),
                    $contact,
                    $request->validated('notes'),
                    $request->validated('confirmed_price'),
                    $request->counterToken(),
                    $request->payKind(),
                    $request->payMethod(),
                    $request->gcashReference(),
                    $request->waiveReason(),
                );

                return $result['created'] ? $result['booking'] : throw new CounterRecordAlreadySaved($result['booking']);
            });
        } catch (CounterRecordAlreadySaved $saved) {
            $booking = $saved->record;
        } catch (ValidationException $e) {
            return $this->refuse(route('owner.bookings.walk-in.create'), $e->errors());
        } catch (BookingTransitionException $e) {
            return $this->refuse(route('owner.bookings.walk-in.create'), ['starts_at' => $e->getMessage()]);
        } catch (PaymentException $e) {
            return $this->refuse(route('owner.bookings.walk-in.create'), ['pay_kind' => $e->getMessage()]);
        }

        /** @var PhotographyBooking $booking */
        return redirect()->route('owner.bookings.show', $booking)->with('status', $booking->status === BookingStatus::AwaitingDownpayment
            ? 'Walk-in booking '.$booking->reference_number.' saved. It holds its time until the downpayment deadline; call the customer if it is not paid.'
            : 'Walk-in booking '.$booking->reference_number.' saved and confirmed.');
    }

    /** Phase 10J: the customer chosen before a refused send, or the one the walk-in customer page sent (?customer=id). */
    private function picked(): ?User
    {
        $id = (int) (old('customer_id') ?? request()->query('customer'));

        return $id > 0 ? $this->customers->find($id) : null;
    }

    /** The customer the owner picked, or a new walk-in customer (inside the caller's transaction). */
    private function customer(WalkInRequest $request): User
    {
        if ($id = $request->customerId()) {
            return $this->customers->find($id) ?? throw ValidationException::withMessages(['customer_id' => 'Choose the customer again.']);
        }

        return $this->customers->create($request->newCustomerName(), $request->newCustomerPhone());
    }

    /** @param  array<string, mixed>  $errors */
    private function refuse(string $url, array $errors): RedirectResponse
    {
        return redirect()->to($url)->withErrors($errors)->withInput();
    }
}
