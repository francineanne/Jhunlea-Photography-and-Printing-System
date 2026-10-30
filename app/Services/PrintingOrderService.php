<?php

namespace App\Services;

use App\Enums\PrintingOrderStatus;
use App\Enums\UserRole;
use App\Exceptions\FileRemovalException;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use App\Support\ShopSettings;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Places a printing order. The reference number, the estimate, the snapshot and the first history row are saved
 * in one transaction: either the whole order exists or none of it does. placeWithFiles() adds the customer's files to
 * that same promise.
 */
class PrintingOrderService
{
    public const BOOKING_MESSAGE = 'Prints can be ordered only from your own photoshoot once it is completed.';

    public function __construct(
        private readonly ReferenceNumberService $references,
        private readonly PricingService $pricing,
        private readonly StatusTransitionService $transitions,
        private readonly OrderFileService $files,
        private readonly ShopSettings $settings,
    ) {}

    /**
     * @param  array<int, int|string>  $optionIds
     *
     * A completed photoshoot of the same customer may be attached (prints after the shoot). It only links the two: the
     * order keeps its own price and status, and the booking's price is never touched.
     *
     * @throws AuthorizationException when the user is not a customer
     * @throws InvalidArgumentException when the service is not published or the options, quantity or pages are not valid
     * @throws ValidationException when the booking is not the customer's own completed photoshoot
     */
    public function place(User $customer, PrintingService $service, array $optionIds, int $quantity, int $pages = 1, ?string $notes = null, ?PhotographyBooking $booking = null): PrintingOrder
    {
        if ($customer->role !== UserRole::Customer) {
            throw new AuthorizationException('Only customers place printing orders.');
        }

        if ($service->trashed() || ! $service->is_published) {
            throw new InvalidArgumentException('This printing service is not available.');
        }

        return DB::transaction(function () use ($customer, $service, $optionIds, $quantity, $pages, $notes, $booking) {
            $this->checkBooking($customer, $booking);

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
                'photography_booking_id' => $booking?->getKey(),
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
     * @throws ValidationException when there is no file, too many files, a file is not acceptable or the files together are too large
     */
    public function placeWithFiles(User $customer, PrintingService $service, array $optionIds, int $quantity, int $pages, ?string $notes, array $uploads, ?PhotographyBooking $booking = null): PrintingOrder
    {
        if ($uploads === []) {
            throw ValidationException::withMessages(['files' => 'Add at least one file to print.']);
        }

        if (count($uploads) > $this->settings->printingMaxFiles()) {
            throw ValidationException::withMessages(['files' => 'You can send up to '.$this->settings->printingMaxFiles().' files with one order.']);
        }

        foreach ($uploads as $upload) {
            $this->files->validate($upload, $service);
        }

        $this->files->validateTotal($uploads);

        return $this->writingFiles(function (array &$written, ?int &$orderId) use ($customer, $service, $optionIds, $quantity, $pages, $notes, $uploads, $booking) {
            $order = $this->place($customer, $service, $optionIds, $quantity, $pages, $notes, $booking);
            $orderId = $order->getKey();

            foreach ($uploads as $upload) {
                $written[] = $this->files->store($order, $upload, $customer)->path;
            }

            return $order;
        });
    }

    /** The attached booking is read again here: it must be this customer's own and completed right now. */
    private function checkBooking(User $customer, ?PhotographyBooking $booking): void
    {
        if ($booking === null) {
            return;
        }

        $current = PhotographyBooking::query()->find($booking->getKey());

        if ($current === null || $current->customer_id !== $customer->getKey() || ! $current->status->allowsPrintOrders()) {
            throw ValidationException::withMessages(['booking' => self::BOOKING_MESSAGE]);
        }
    }

    /**
     * Adds files to an existing order (customer or owner), with the same per-file, count and total rules as a new
     * order. All files are checked first and saved together; if the customer was asked for more information,
     * adding files answers it (StatusTransitionService::recordFilesAdded).
     *
     * @param  list<UploadedFile>  $uploads
     *
     * @throws AuthorizationException when the user may not add files to this order
     * @throws ValidationException when a file, the count or the total is not acceptable
     */
    public function addFiles(PrintingOrder $order, User $actor, array $uploads): PrintingOrder
    {
        $service = $order->service;
        $existing = $order->files()->count();

        if ($uploads === []) {
            throw ValidationException::withMessages(['files' => 'Add at least one file.']);
        }

        if ($existing + count($uploads) > $this->settings->printingMaxFiles()) {
            $room = max(0, $this->settings->printingMaxFiles() - $existing);

            throw ValidationException::withMessages(['files' => 'An order can hold up to '.$this->settings->printingMaxFiles().' files. '.($room > 0 ? "You can add {$room} more." : 'It is full.')]);
        }

        foreach ($uploads as $upload) {
            $this->files->validate($upload, $service);
        }

        $this->files->validateTotal($uploads, (int) $order->files()->sum('size_bytes'));

        return $this->writingFiles(function (array &$written) use ($order, $actor, $uploads) {
            foreach ($uploads as $upload) {
                $written[] = $this->files->store($order, $upload, $actor)->path;
            }

            return $this->transitions->recordFilesAdded($order, $actor, count($uploads));
        });
    }

    /**
     * A customer removes one of their own files from an order that still takes files.
     *
     * The order row is locked and read again, and every rule is checked again inside the transaction: the customer's
     * order, a file they uploaded themselves, a status that takes files, and never the last file. The file on the disk
     * is deleted only after the transaction commits, so a failed save never leaves a row pointing at a missing file.
     * A failed disk delete is reported for the shop and not shown to the customer: the row is already gone.
     *
     * @throws AuthorizationException when it is not the customer's order or not their upload
     * @throws FileRemovalException when the order no longer takes files, it is the last file, or the file is already gone
     */
    public function removeFile(PrintingOrder $order, int $fileId, User $customer): string
    {
        return DB::transaction(function () use ($order, $fileId, $customer) {
            $current = PrintingOrder::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($customer->role !== UserRole::Customer || $current->customer_id !== $customer->id) {
                throw new AuthorizationException('This order belongs to another customer.');
            }

            $file = $current->files()->whereKey($fileId)->lockForUpdate()->first();

            if ($file === null) {
                throw FileRemovalException::gone();
            }

            if ($file->uploaded_by !== $customer->id) {
                throw new AuthorizationException('Files added by the shop cannot be removed.');
            }

            if (! $current->status->acceptsFiles()) {
                throw FileRemovalException::closed($current->status);
            }

            if ($current->files()->count() <= 1) {
                throw FileRemovalException::lastFile();
            }

            $path = $file->path;
            $name = $file->original_name;

            $file->delete();
            $this->transitions->recordFileRemoved($current, $customer, $name);

            DB::afterCommit(function () use ($path) {
                try {
                    if (! Storage::disk(config('printing.disk'))->delete($path)) {
                        report(new RuntimeException("A removed order file could not be deleted from the disk: {$path}"));
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });

            return $name;
        });
    }

    /**
     * Runs the work in one transaction. If anything fails after a file was written, every file written is deleted,
     * and so is the folder of an order that was rolled back, so no orphan file is left behind.
     *
     * @param  Closure(array<int, string>&, ?int&): PrintingOrder  $work
     */
    private function writingFiles(Closure $work): PrintingOrder
    {
        $written = [];
        $orderId = null;

        try {
            return DB::transaction(function () use ($work, &$written, &$orderId) {
                return $work($written, $orderId);
            });
        } catch (Throwable $e) {
            $disk = Storage::disk(config('printing.disk'));
            $disk->delete($written);

            // A rolled-back order id is not handed out again, so its folder holds nothing else; it goes when empty
            if ($orderId !== null && $disk->allFiles(config('printing.directory').'/'.$orderId) === []) {
                $disk->deleteDirectory(config('printing.directory').'/'.$orderId);
            }

            throw $e;
        }
    }
}
