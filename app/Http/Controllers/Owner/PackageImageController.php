<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\StorePackageImagesRequest;
use App\Http\Requests\Owner\UpdatePackageImageRequest;
use App\Models\PackageImage;
use App\Models\PhotographyPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The routes use scoped bindings, so {image} must belong to {package} or the request is a 404.
 *
 * Every change that touches the cover runs in a transaction that locks the package row first, so two requests at the
 * same time cannot leave a package with two covers or none. Files are removed only after the database change has committed.
 */
class PackageImageController extends Controller
{
    public function store(StorePackageImagesRequest $request, PhotographyPackage $package): RedirectResponse
    {
        $stored = [];

        try {
            foreach ($request->file('images') as $file) {
                // hashName() makes the name and takes the extension from the file's real type, never from the client's name
                $path = $file->store("package-images/{$package->id}", PackageImage::DISK);

                if ($path === false) {
                    throw new \RuntimeException('The photo could not be written to the disk.');
                }

                $stored[] = $path;
            }

            DB::transaction(function () use ($package, $stored) {
                $locked = PhotographyPackage::query()->whereKey($package->id)->lockForUpdate()->firstOrFail();

                // The request checked the limit, but another upload may have added photos since
                if ($locked->images()->count() + count($stored) > PackageImage::MAX_PER_PACKAGE) {
                    throw ValidationException::withMessages(['images' => 'A package can have at most '.PackageImage::MAX_PER_PACKAGE.' photos.']);
                }

                $hasCover = $locked->images()->where('is_cover', true)->exists();
                $order = (int) $locked->images()->max('sort_order');

                foreach ($stored as $index => $path) {
                    $image = new PackageImage;
                    $image->photography_package_id = $locked->id;
                    $image->path = $path;
                    $image->is_cover = ! $hasCover && $index === 0;
                    $image->sort_order = ++$order;
                    $image->save();
                }
            });
        } catch (Throwable $e) {
            // Nothing was saved, so nothing may stay on disk
            Storage::disk(PackageImage::DISK)->delete($stored);

            if ($e instanceof ValidationException) {
                throw $e;
            }

            report($e);

            return $this->back($package)->with('error', 'The photos could not be saved. Nothing was uploaded; please try again.');
        }

        $count = count($stored);

        return $this->back($package)->with('status', $count === 1 ? 'The photo was added.' : "{$count} photos were added.");
    }

    public function update(UpdatePackageImageRequest $request, PhotographyPackage $package, PackageImage $image): RedirectResponse
    {
        $image->update($request->validated());

        return $this->back($package)->with('status', 'The photo description was saved.');
    }

    public function cover(PhotographyPackage $package, PackageImage $image): RedirectResponse
    {
        DB::transaction(function () use ($package, $image) {
            PhotographyPackage::query()->whereKey($package->id)->lockForUpdate()->firstOrFail();

            // The photo was loaded before the lock: if another request deleted it meanwhile, stop before the covers are reset
            abort_unless($package->images()->whereKey($image->id)->exists(), 404);

            // Two plain updates: the in-memory photo may already be the cover, which would make a model save do nothing
            $package->images()->update(['is_cover' => false]);
            $package->images()->whereKey($image->id)->update(['is_cover' => true]);
        });

        return $this->back($package)->with('status', 'The cover photo was changed.');
    }

    public function destroy(PhotographyPackage $package, PackageImage $image): RedirectResponse
    {
        DB::transaction(function () use ($package, $image) {
            PhotographyPackage::query()->whereKey($package->id)->lockForUpdate()->firstOrFail();

            // Read again under the lock: another request may have changed or removed this photo since it was loaded
            $current = $package->images()->whereKey($image->id)->first();

            if ($current === null) {
                return;
            }

            $path = $current->path;
            $wasCover = $current->is_cover;
            $current->delete();

            // The next photo takes over as cover, so a package with photos always has exactly one
            if ($wasCover) {
                $package->images()->first()?->forceFill(['is_cover' => true])->save();
            }

            DB::afterCommit(function () use ($path) {
                if (! Storage::disk(PackageImage::DISK)->delete($path)) {
                    Log::warning('A deleted package photo could not be removed from the disk.', ['path' => $path]);
                }
            });
        });

        return $this->back($package)->with('status', 'The photo was deleted.');
    }

    private function back(PhotographyPackage $package): RedirectResponse
    {
        return redirect()->to(route('owner.packages.edit', $package).'#gallery');
    }
}
