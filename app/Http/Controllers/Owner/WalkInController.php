<?php

namespace App\Http\Controllers\Owner;

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
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\Theme;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\ReferenceNumberService;
use App\Services\WalkIn\CounterRecordAlreadySaved;
use App\Services\WalkIn\WalkInBookingService;
use App\Services\WalkIn\WalkInCustomerService;
use App\Services\WalkIn\WalkInOrderService;
use App\Support\LocalTime;
use App\Support\Money;
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
            'picked' => old('customer_id') ? $this->customers->find((int) old('customer_id')) : null,
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

        $tendered = $request->validated('cash_tendered');
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
            'token' => (string) Str::uuid(),
            'picked' => old('customer_id') ? $this->customers->find((int) old('customer_id')) : null,
        ]);
    }

    /** The free start times of a package on a date at the counter (no minimum notice, no advance limit). JSON. */
    public function slots(WalkInLookupRequest $request, AvailabilityService $availability): JsonResponse
    {
        $package = PhotographyPackage::query()->where('is_published', true)->find((int) $request->validated('package'));
        $date = $request->validated('date');

        if ($package === null || $date === null) {
            return response()->json(['times' => []]);
        }

        return response()->json(['times' => $availability->slots($package->duration_minutes, $date, $date, atCounter: true)
            ->map(fn ($slot) => ['value' => LocalTime::format($slot->startsAt, 'H:i'), 'label' => LocalTime::format($slot->startsAt, 'g:i A')])
            ->values()]);
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
                $result = $bookings->place(
                    $request->user(),
                    $this->customer($request),
                    $package,
                    $theme,
                    $request->validated('custom_theme_text'),
                    $request->startsAt(),
                    (int) $request->validated('group_size'),
                    (string) $request->validated('contact_number'),
                    $request->validated('notes'),
                    $request->validated('confirmed_price'),
                    (bool) $request->validated('outside_schedule'),
                    $request->counterToken(),
                );

                return $result['created'] ? $result['booking'] : throw new CounterRecordAlreadySaved($result['booking']);
            });
        } catch (CounterRecordAlreadySaved $saved) {
            $booking = $saved->record;
        } catch (ValidationException $e) {
            return $this->refuse(route('owner.bookings.walk-in.create'), $e->errors());
        } catch (BookingTransitionException $e) {
            return $this->refuse(route('owner.bookings.walk-in.create'), ['starts_at' => $e->getMessage()]);
        }

        /** @var PhotographyBooking $booking */
        return redirect()->route('owner.bookings.show', $booking)->with('status', 'Walk-in booking '.$booking->reference_number.' saved and confirmed.');
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
