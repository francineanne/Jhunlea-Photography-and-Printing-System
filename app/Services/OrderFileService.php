<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\OrderFile;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Checks and stores the files of a printing order on the private disk. The rules (disk, types, sizes, limits) all
 * live in config/printing.php. A stored file has a generated name (hashName); the customer's own file name is kept
 * only as text for display. There is no way to download a file yet: that comes with an authorized route in Phase 3C.
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
     * The limit for all files of one order together. Each file is still checked on its own by validate().
     *
     * @param  array<int, UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public function validateTotal(array $files): void
    {
        $total = array_sum(array_map(fn (UploadedFile $file) => (int) $file->getSize(), $files));

        if ($total > $this->maxTotalBytes()) {
            // Rounded up, so a total just over the limit never reads as the limit itself
            $size = number_format(ceil($total / 1024 / 1024 * 10) / 10, 1);

            throw ValidationException::withMessages(['files' => "Your files together are {$size} MB. The most one order can hold is ".config('printing.max_total_size_mb').' MB. Please remove or compress some files.']);
        }
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
                'original_name' => $this->displayName($file->getClientOriginalName()),
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
        if (! $file->isValid()) {
            $this->fail('The file did not upload completely. Please try again.');
        }

        if ($order !== null && $order->files()->count() >= config('printing.max_files_per_order')) {
            $this->fail('This order already has the most files it can hold ('.config('printing.max_files_per_order').').');
        }

        $size = $file->getSize();

        if (! $size) {
            $this->fail('The file is empty.');
        }

        $maxKb = $this->maxKilobytes($service);

        if ($size > $maxKb * 1024) {
            $this->fail('The file is too large. The most this service accepts is '.intdiv($maxKb, 1024).' MB.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $type = $this->typeFor($service, $extension);

        if ($type === null) {
            $this->fail('This service accepts only '.strtoupper(implode(', ', $service->allowed_file_types ?? [])).' files.');
        }

        // The type the server finds by reading the file itself, not the one the browser claims
        if (! in_array($file->getMimeType(), config("printing.types.{$type}.mimes"), true)) {
            $this->fail('The contents of this file do not match a .'.$extension.' file.');
        }
    }

    /** The config key (pdf, jpg...) of the type this service accepts for the given extension, or null. */
    private function typeFor(PrintingService $service, string $extension): ?string
    {
        foreach ($service->allowed_file_types ?? [] as $type) {
            if (in_array($extension, config("printing.types.{$type}.extensions", []), true)) {
                return $type;
            }
        }

        return null;
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

    /** Only for showing to people: no folders, no control characters, a sensible length. */
    private function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        return mb_substr($name === '' ? 'file' : $name, 0, 255);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
