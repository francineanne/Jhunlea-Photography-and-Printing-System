<?php

namespace App\Services\WalkIn;

use App\Enums\PaymentMethod;
use App\Enums\PrintingOrderStatus;
use App\Enums\RecordSource;
use App\Enums\UserRole;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use App\Services\OrderFileService;
use App\Services\PaymentService;
use App\Services\PricingService;
use App\Services\ReferenceNumberService;
use App\Services\StatusTransitionService;
use App\Support\Money;
use App\Support\PaymentRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * A walk-in printing order made by the owner at the counter (Phase 8A; the rules since Phase 10J). The owner reviewed
 * it at the counter, so it starts Accepted with its confirmed price (source walk_in, first history row), and from there
 * it follows the ONLINE lifecycle: the same pay-first rule as an accepted online order (requires_prepayment from the
 * "Pay first from" amount or the customer's flag), Processing only when that rule allows it, Ready for pickup,
 * Completed only when fully paid, Unclaimed after the setting.
 *
 * "Paid now" is optional (Phase 10J): with a payment method the full price is recorded through PaymentService in the
 * same transaction and the order moves on to Processing (it is settled, so the usual move is allowed). Without one it
 * stays Accepted and is paid later at the counter, like an online order. If anything fails, nothing is left behind
 * (and files already written are deleted). Removed in 10J: the rule that a walk-in order is never saved unpaid.
 *
 * The form's one-time counter token is stored on the order (unique): the same form sent twice returns the order the
 * first send made, so a double click or a retry never makes two orders or two payments. Phase 8B's Quick walk-in calls
 * place() as it is.
 */
class WalkInOrderService
{
    public const PRICE_CATALOG = 'catalog';

    public const PRICE_CUSTOM = 'custom';

    public const NOTE_MAX = 1000;

    public const PRICE_NOTE_MAX = 200;

    public function __construct(
        private readonly ReferenceNumberService $references,
        private readonly PricingService $pricing,
        private readonly StatusTransitionService $transitions,
        private readonly OrderFileService $files,
        private readonly PaymentService $payments,
    ) {}

    /**
     * The catalog price of these choices (PricingService), or null when the service is priced by quotation.
     *
     * @param  array<int, int|string>  $optionIds
     */
    public function catalogPrice(PrintingService $service, array $optionIds, int $quantity, int $pages): ?string
    {
        return $this->pricing->estimate($service, $optionIds, $quantity, $pages)->total;
    }

    /**
     * @param  array<int, int|string>  $optionIds
     * @param  list<UploadedFile>  $uploads
     * @return array{order: PrintingOrder, created: bool} created is false when this token already made the order
     *
     * @throws AuthorizationException when the actor is not the owner
     * @throws InvalidArgumentException when the service, options, quantity or pages are not valid (PricingService)
     * @throws ValidationException when the price, a file or the payment is refused (the key names the field)
     */
    public function place(
        User $owner,
        User $customer,
        PrintingService $service,
        array $optionIds,
        int $quantity,
        int $pages,
        ?string $notes,
        string $priceChoice,
        ?string $customPrice,
        ?string $priceNote,
        ?PaymentMethod $method,
        ?string $gcashReference,
        string $counterToken,
        array $uploads = [],
    ): array {
        if ($owner->role !== UserRole::Owner) {
            throw new AuthorizationException('Only the owner makes walk-in orders.');
        }

        if ($customer->role !== UserRole::Customer) {
            throw ValidationException::withMessages(['customer_id' => 'Choose a customer.']);
        }

        if ($existing = $this->byToken($counterToken)) {
            return ['order' => $existing, 'created' => false];
        }

        if ($service->trashed() || ! $service->is_published) {
            throw ValidationException::withMessages(['service' => 'This printing service is not available.']);
        }

        $estimate = $this->pricing->estimate($service, $optionIds, $quantity, $pages);
        [$price, $priceNote] = $this->price($estimate->total, $priceChoice, $customPrice, $priceNote);

        foreach ($uploads as $upload) {
            $this->files->validate($upload, $service);
        }
        $this->files->validateTotal($uploads);

        $notes = $notes === null || trim($notes) === '' ? null : trim($notes);
        $written = [];
        $orderId = null;

        try {
            return DB::transaction(function () use ($owner, $customer, $service, $estimate, $quantity, $notes, $price, $priceChoice, $priceNote, $method, $gcashReference, $counterToken, $uploads, &$written, &$orderId) {
                // Lock order (Phase 9B, found on MySQL): the payment number is locked FIRST. This transaction writes
                // audit rows (the chain head lock) before it records the payment, while a normal payment locks its
                // counter before the head; taking the counter first here keeps the two from deadlocking
                $this->references->lock(ReferenceNumberService::PAYMENT);

                $snapshot = $estimate->snapshot($service->name, $service->pricing_type->value, $service->base_price);
                // The price the owner charged and who set it, kept with the order (the audit trail of a custom price)
                $snapshot['counter'] = [
                    'price_source' => $priceChoice,
                    'catalog_price' => $estimate->total,
                    'price' => $price,
                    'price_set_by' => $owner->getKey(),
                    'price_note' => $priceNote,
                ];

                $order = new PrintingOrder(['quantity' => $quantity, 'notes' => $notes]);
                // Phase 10J: the same pay-first rule as an online order the shop accepts (the flag, else the amount)
                $prepay = (bool) User::query()->whereKey($customer->getKey())->value('prepay_required')
                    || app(PaymentRules::class)->prepayByPrice($price);

                $order->forceFill([
                    'requires_prepayment' => $prepay,
                    'reference_number' => $this->references->next(ReferenceNumberService::PRINTING),
                    'customer_id' => $customer->getKey(),
                    'printing_service_id' => $service->getKey(),
                    'snapshot' => $snapshot,
                    'status' => PrintingOrderStatus::Accepted,
                    'source' => RecordSource::WalkIn,
                    'counter_token' => $counterToken,
                    'estimated_price' => $estimate->total,
                    'confirmed_price' => $price,
                ])->save();
                $orderId = $order->getKey();

                $this->transitions->recordCounterOrder($order, $owner, $this->historyNote($priceChoice, $price, $estimate->total, $priceNote));

                foreach ($uploads as $upload) {
                    $written[] = $this->files->storeAtCounter($order, $upload, $owner)->path;
                }

                // "Paid now": every Phase 5 rule (the full confirmed price, once, with its history row and ledger entry),
                // then the usual owner move Accepted -> Processing, which the pay-first rule allows once it is paid
                if ($method !== null) {
                    $this->payments->record($order, $owner, $method, $gcashReference, $price);
                    $this->transitions->transition($order->refresh(), PrintingOrderStatus::Processing, $owner, 'Paid at the counter; printing starts.');
                }

                return ['order' => $order->refresh(), 'created' => true];
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->forget($written, $orderId);

            // The same form was sent twice at once and the other send saved first
            if ($existing = $this->byToken($counterToken)) {
                return ['order' => $existing, 'created' => false];
            }

            throw $e;
        } catch (Throwable $e) {
            $this->forget($written, $orderId);

            throw $e;
        }
    }

    /** @return array{0: string, 1: ?string} the price to charge and the owner's note about it */
    private function price(?string $catalogPrice, string $choice, ?string $customPrice, ?string $note): array
    {
        $note = $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, self::PRICE_NOTE_MAX);

        if ($choice === self::PRICE_CATALOG) {
            if ($catalogPrice === null || Money::toCents($catalogPrice) <= 0) {
                throw ValidationException::withMessages(['price_choice' => 'This service has no catalog price for these choices. Type the price.']);
            }

            return [$catalogPrice, null];
        }

        if ($choice !== self::PRICE_CUSTOM || $customPrice === null || ! preg_match('/^\d{1,8}(\.\d{1,2})?$/', $customPrice) || Money::toCents($customPrice) <= 0) {
            throw ValidationException::withMessages(['custom_price' => 'Type the price, more than ₱0.00, for example 150 or 150.50.']);
        }

        return [Money::fromCents(Money::toCents($customPrice)), $note];
    }

    private function historyNote(string $choice, string $price, ?string $catalogPrice, ?string $priceNote): string
    {
        $note = 'Created at the counter (walk-in). Price '.Money::format($price);

        if ($choice === self::PRICE_CUSTOM) {
            $note .= ' set by the owner'.($catalogPrice !== null ? ' (catalog price '.Money::format($catalogPrice).')' : '').($priceNote ? ': '.$priceNote : '');
        }

        return $note.'.';
    }

    private function byToken(string $token): ?PrintingOrder
    {
        return PrintingOrder::query()->where('counter_token', $token)->first();
    }

    /** @param  list<string>  $written */
    private function forget(array $written, ?int $orderId): void
    {
        $disk = Storage::disk(config('printing.disk'));
        $disk->delete($written);

        if ($orderId !== null && $disk->allFiles(config('printing.directory').'/'.$orderId) === []) {
            $disk->deleteDirectory(config('printing.directory').'/'.$orderId);
        }
    }
}
