<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\ThemeRequest;
use App\Models\PackageImage;
use App\Models\PhotographyPackage;
use App\Models\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class ThemeController extends Controller
{
    private const PER_PAGE = 12;

    public function index(Request $request): View
    {
        // An array such as ?search[]=x is ignored instead of crashing
        $search = is_string($request->query('search')) ? trim($request->query('search')) : '';
        $status = in_array($request->query('status'), ['published', 'draft'], true) ? $request->query('status') : null;

        $themes = Theme::query()
            ->withCount('packages')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($status, fn ($query) => $query->where('is_published', $status === 'published'))
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('owner.themes.index', [
            'themes' => $themes,
            'search' => $search,
            'status' => $status,
            'hasFilters' => $search !== '' || $status !== null,
            'stats' => $this->catalogStats(),
        ]);
    }

    /**
     * Totals for the tiles above the list, in one query. Always the whole library, never the current search or tab;
     * soft-deleted themes and packages are left out.
     *
     * @return array<string, int>
     */
    private function catalogStats(): array
    {
        $row = Theme::query()
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(is_published), 0) as published')
            ->selectSub(
                DB::table('package_theme')
                    ->join('themes as t', 't.id', '=', 'package_theme.theme_id')
                    ->join('photography_packages as p', 'p.id', '=', 'package_theme.photography_package_id')
                    ->whereNull('t.deleted_at')
                    ->whereNull('p.deleted_at')
                    ->selectRaw('COUNT(DISTINCT package_theme.theme_id)'),
                'in_use',
            )
            ->toBase()
            ->first();

        return [
            'total' => (int) $row->total,
            'published' => (int) $row->published,
            'unpublished' => (int) $row->total - (int) $row->published,
            'in_use' => (int) $row->in_use,
        ];
    }

    public function create(): View
    {
        return view('owner.themes.create', $this->formData(new Theme(['color_swatches' => [], 'is_published' => false]), []));
    }

    public function store(ThemeRequest $request): RedirectResponse
    {
        $data = $this->attributes($request);
        $packageIds = $request->validated('package_ids') ?? [];
        $newPath = $request->file('image') ? $this->storeImage($request->file('image')) : null;

        try {
            $theme = DB::transaction(function () use ($data, $packageIds, $newPath) {
                $theme = new Theme($data);
                $theme->image_path = $newPath;
                $theme->save();
                $theme->packages()->sync($packageIds);

                return $theme;
            });
        } catch (Throwable $e) {
            // Nothing was saved, so the new file must not stay on disk
            $this->deleteFile($newPath);
            report($e);

            return back()->withInput()->with('error', 'The theme could not be saved. Nothing was changed; please try again.');
        }

        return redirect()->route('owner.themes.edit', $theme)->with('status', "\"{$theme->name}\" was created.");
    }

    public function edit(Theme $theme): View
    {
        return view('owner.themes.edit', $this->formData($theme, $theme->packages()->pluck('photography_packages.id')->all()));
    }

    public function update(ThemeRequest $request, Theme $theme): RedirectResponse
    {
        $data = $this->attributes($request);
        $packageIds = $request->validated('package_ids') ?? [];
        $oldPath = $theme->image_path;
        $newPath = $request->file('image') ? $this->storeImage($request->file('image')) : null;
        $removeOld = $newPath !== null || $request->boolean('remove_image');

        try {
            DB::transaction(function () use ($theme, $data, $packageIds, $newPath, $removeOld, $oldPath) {
                $theme->fill($data);

                if ($removeOld) {
                    $theme->image_path = $newPath;
                }

                $theme->save();
                $theme->packages()->sync($packageIds);

                // The old file goes only after the change has committed, so a failed save never loses the photo
                if ($removeOld && $oldPath) {
                    DB::afterCommit(fn () => $this->deleteFile($oldPath));
                }
            });
        } catch (Throwable $e) {
            $this->deleteFile($newPath);
            report($e);

            return back()->withInput()->with('error', 'The theme could not be saved. Nothing was changed; please try again.');
        }

        return redirect()->route('owner.themes.edit', $theme)->with('status', "\"{$theme->name}\" was saved.");
    }

    public function destroy(Theme $theme): RedirectResponse
    {
        // Soft delete: its photo stays on disk with it
        $theme->delete();

        return redirect()->route('owner.themes.index')->with('status', "\"{$theme->name}\" was deleted.");
    }

    public function publish(Theme $theme): RedirectResponse
    {
        $theme->update(['is_published' => ! $theme->is_published]);

        return back()->with('status', $theme->is_published
            ? "\"{$theme->name}\" is now published."
            : "\"{$theme->name}\" is now unpublished.");
    }

    /** @return array<string, mixed> */
    private function attributes(ThemeRequest $request): array
    {
        return collect($request->validated())->only(['name', 'description', 'color_swatches', 'is_published'])->all();
    }

    /** hashName() makes the name and takes the extension from the file's real type, never from the client's name. */
    private function storeImage(UploadedFile $file): string
    {
        $path = $file->store('theme-images', PackageImage::DISK);

        if ($path === false) {
            throw new \RuntimeException('The theme photo could not be written to the disk.');
        }

        return $path;
    }

    private function deleteFile(?string $path): void
    {
        if ($path && ! Storage::disk(PackageImage::DISK)->delete($path)) {
            Log::warning('A theme photo could not be removed from the disk.', ['path' => $path]);
        }
    }

    /**
     * @param  array<int, int>  $selectedPackageIds
     * @return array<string, mixed>
     */
    private function formData(Theme $theme, array $selectedPackageIds): array
    {
        return [
            'theme' => $theme,
            'packages' => PhotographyPackage::query()->orderBy('name')->get(['id', 'name', 'is_published']),
            'selectedPackageIds' => $selectedPackageIds,
        ];
    }
}
