<?php

namespace App\Services;

use App\Models\User;
use App\Support\UploadCheck;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Phase 10C: the ONE writer of users.avatar_path. A JPG, PNG or WEBP up to 2 MB, checked by its extension AND the MIME
 * type read from the file itself (UploadCheck), stored on the public disk under avatars/ with a random name (never the
 * customer's file name). A new picture replaces the old one, whose file is deleted once the new path is saved; Remove
 * deletes it. Walk-ins never have one (they cannot sign in). Shown square with CSS (object-fit), no image package.
 */
class AvatarService
{
    public const DISK = 'public';

    public const FOLDER = 'avatars';

    public const MAX_MB = 2;

    /** @var array<string, array{extensions: list<string>, mimes: list<string>}> */
    public const TYPES = [
        'jpg' => ['extensions' => ['jpg', 'jpeg'], 'mimes' => ['image/jpeg']],
        'png' => ['extensions' => ['png'], 'mimes' => ['image/png']],
        'webp' => ['extensions' => ['webp'], 'mimes' => ['image/webp']],
    ];

    /** @throws ValidationException */
    public function replace(User $user, UploadedFile $file): void
    {
        if ($user->is_walk_in) {
            throw ValidationException::withMessages(['avatar' => 'A walk-in customer has no profile picture.'])->errorBag('avatar');
        }

        try {
            UploadCheck::file($file, self::TYPES, self::MAX_MB * 1024 * 1024,
                'The picture is larger than '.self::MAX_MB.' MB. Please choose a smaller one.',
                'Choose a JPG, PNG or WEBP picture.', 'avatar');
        } catch (ValidationException $e) {
            throw $e->errorBag('avatar');
        }

        $path = $file->storeAs(self::FOLDER, $file->hashName(), self::DISK);

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['avatar' => 'The picture could not be saved. Please try again.'])->errorBag('avatar');
        }

        try {
            $old = DB::transaction(function () use ($user, $path) {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                $old = $locked->avatar_path;
                $locked->forceFill(['avatar_path' => $path])->save();

                return $old;
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path); // nothing stray when the save fails

            throw $e;
        }

        $user->forceFill(['avatar_path' => $path])->syncOriginalAttribute('avatar_path');
        $this->deleteFile($old);
    }

    /** Removes the picture; false when there was none. */
    public function remove(User $user): bool
    {
        $old = DB::transaction(function () use ($user) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $old = $locked->avatar_path;

            if ($old !== null) {
                $locked->forceFill(['avatar_path' => null])->save();
            }

            return $old;
        });

        $user->forceFill(['avatar_path' => null])->syncOriginalAttribute('avatar_path');
        $this->deleteFile($old);

        return $old !== null;
    }

    private function deleteFile(?string $path): void
    {
        // Only ever a file this service stored, inside avatars/
        if (is_string($path) && str_starts_with($path, self::FOLDER.'/') && ! str_contains($path, '..')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
