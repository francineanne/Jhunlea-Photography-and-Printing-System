<?php

namespace App\Services;

use App\Enums\PrintingOrderStatus;
use App\Enums\UserRole;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Places a printing order. The reference number, the estimate, the snapshot and the first history row are saved
 * in one transaction: either the whole order exists or none of it does. placeWithFiles() adds the customer's files to
 * that same promise.
 */
class PrintingOrderService
{
    public function __construct(
        private readonly ReferenceNumberService $references,
        private readonly PricingService $pricing,
        private readonly StatusTransitionService $transitions,
        private readonly OrderFileService $files,
    ) {}

    /**
     * @param  array<int, int|string>  $optionIds
     *
     * @throws AuthorizationException when the user is not a customer
     * @throws InvalidArgumentException when the service is not published or the options, quantity or pages are not valid
     */
    public function place(User $customer, PrintingService $service, array $optionIds, int $quantity, int $pages = 1, ?string $notes = null): PrintingOrder
    {
        if ($customer->role !== UserRole::Customer) {
            throw new AuthorizationException('Only customers place printing orders.');
        }

        if ($service->trashed() || ! $service->is_published) {
            throw new InvalidArgumentException('This printing service is not available.');
        }

        return DB::transaction(function () use ($customer, $service, $optionIds, $quantity, $pages, $notes) {
            $estimate = $this->pricing->estimate($service, $optionIds, $quantity, $pages);
            $notes = $notes === null ? null : trim($notes);

            $order = new PrintingOrder(['quantity' => $quantity, 'notes' => $notes === '' ? null : $notes]);
            $order->forceFill([
                'reference_number' => $this->references->next(ReferenceNumberService::PRINTING),
                'customer_id' => $customer->getKey(),
                'printing_service_id' => $service->getKey(),
                'snapshot' => $estimate->snapshot($service->name, $service->pricing_type->value, $service->base_price),
                'status' => PrintingOrderStatus::Submitted,
                'estimated_price' => $estimate->total,
            ])->save();

            $this->transitions->recordSubmission($order, $customer);

            return $order;
        });
    }

    /**
     * Places an order together with its files.
     *
     * Every file is checked first, before anything is saved. Then the order and the files are saved in one transaction.
     * If anything fails after a file has been written (a later file, the database, the disk), the transaction rolls the
     * order back and every file already written is deleted, so no half order and no orphan file is left behind.
     *
     * @param  array<int, int|string>  $optionIds
     * @param  list<UploadedFile>  $uploads
     *
     * @throws AuthorizationException when the user is not a customer
     * @throws InvalidArgumentException when the service, options, quantity or pages are not valid
     * @throws ValidationException when there is no file, too many files, or a file is not acceptable
     */
    public function placeWithFiles(User $customer, PrintingService $service, array $optionIds, int $quantity, int $pages, ?string $notes, array $uploads): PrintingOrder
    {
        if ($uploads === []) {
            throw ValidationException::withMessages(['files' => 'Add at least one file to print.']);
        }

        if (count($uploads) > config('printing.max_files_per_order')) {
            throw ValidationException::withMessages(['files' => 'You can send up to '.config('printing.max_files_per_order').' files with one order.']);
        }

        foreach ($uploads as $upload) {
            $this->files->validate($upload, $service);
        }

        $written = [];
        $orderId = null;

        try {
            return DB::transaction(function () use ($customer, $service, $optionIds, $quantity, $pages, $notes, $uploads, &$written, &$orderId) {
                $order = $this->place($customer, $service, $optionIds, $quantity, $pages, $notes);
                $orderId = $order->getKey();

                foreach ($uploads as $upload) {
                    $written[] = $this->files->store($order, $upload, $customer)->path;
                }

                return $order;
            });
        } catch (Throwable $e) {
            $disk = Storage::disk(config('printing.disk'));
            $disk->delete($written);

            // The order id was rolled back with the order and is not handed out again, so its folder holds nothing else
            if ($orderId !== null && $disk->allFiles(config('printing.directory').'/'.$orderId) === []) {
                $disk->deleteDirectory(config('printing.directory').'/'.$orderId);
            }

            throw $e;
        }
    }
}
