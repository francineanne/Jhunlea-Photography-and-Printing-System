<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\OrderFile;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use App\Support\UploadCheck;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Checks and stores the files of a printing order on the private disk. The rules (disk, types, sizes, limits) all
 * live in config/printing.php. A stored file has a generated name (hashName); the customer's own file name is kept
 * only as text for display. Files are handed out only by OrderFileDownloadController, after OrderFilePolicy allows it.
 */
class OrderFileService
{
    /** @return list<string> extensions the service accepts, for example ['pdf', 'jpg', 'jpeg'] */
    public function allowedExtensions(PrintingService $service): array
    {
        $extensions = [];

        foreach ($service->allowed_file_types ?? [] as $type) {
            $extensions = array_merge($extensions, config("printing.types.{$type}.extensions", []));
        }

        return array_values(array_unique($extensions));
    }

    /** The service's own limit, but never above the limit for the whole system. */
    public function maxKilobytes(PrintingService $service): int
    {
        return min($service->max_file_size_mb, config('printing.max_file_size_mb')) * 1024;
    }

    /** All files of one order together, in bytes. */
    public function maxTotalBytes(): int
    {
        return config('printing.max_total_size_mb') * 1024 * 1024;
    }

    /**
     * The limit for all files of one order together, counting the files it already holds. Each file is still checked
     * on its own by validate().
     *
     * @param  array<int, UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public function validateTotal(array $files, int $alreadyStoredBytes = 0): void
    {
        UploadCheck::total($files, (int) config('printing.max_total_size_mb'), 'one order', $alreadyStoredBytes);
    }

    /**
     * Stores one file for an order and records it.
     *
     * @throws AuthorizationException when the uploader may not add files to this order
     * @throws ValidationException when the file is not acceptable
     */
    public function store(PrintingOrder $order, UploadedFile $file, User $uploader): OrderFile
    {
        $this->authorize($order, $uploader);
        $this->validate($file, $order->service, $order);

        $disk = Storage::disk(config('printing.disk'));
        $path = $disk->putFile(config('printing.directory').'/'.$order->getKey(), $file);

        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be saved. Please try again.']);
        }

        try {
            $record = new OrderFile;
            $record->forceFill([
                'printing_order_id' => $order->getKey(),
                'original_name' => UploadCheck::displayName($file->getClientOriginalName()),
                'stored_name' => basename($path),
                'path' => $path,
                'mime' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'uploaded_by' => $uploader->getKey(),
            ])->save();
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        return $record;
    }

    /** @throws ValidationException */
    public function validate(UploadedFile $file, PrintingService $service, ?PrintingOrder $order = null): void
    {
        if ($order !== null && $order->files()->count() >= config('printing.max_files_per_order')) {
            throw ValidationException::withMessages(['file' => 'This order already has the most files it can hold ('.config('printing.max_files_per_order').').']);
        }

        $maxKb = $this->maxKilobytes($service);

        UploadCheck::file(
            $file,
            collect($service->allowed_file_types ?? [])->mapWithKeys(fn (string $type) => [$type => config("printing.types.{$type}")])->filter()->all(),
            $maxKb * 1024,
            'The file is too large. The most this service accepts is '.intdiv($maxKb, 1024).' MB.',
            'This service accepts only '.strtoupper(implode(', ', $service->allowed_file_types ?? [])).' files.',
        );
    }

    private function authorize(PrintingOrder $order, User $uploader): void
    {
        $isOwner = $uploader->role === UserRole::Owner;

        if (! $isOwner && $order->customer_id !== $uploader->id) {
            throw new AuthorizationException('This order belongs to another customer.');
        }

        // Files may be added only while the order is still being looked at. The stored status counts, not a copy in memory
        $status = PrintingOrder::query()->whereKey($order->getKey())->first(['status'])?->status;

        if ($status === null || ! $status->acceptsFiles()) {
            throw new AuthorizationException('Files can no longer be added to this order.');
        }
    }
}
