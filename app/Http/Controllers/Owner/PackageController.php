<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\PackageRequest;
use App\Models\PhotographyPackage;
use App\Models\Theme;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PackageController extends Controller
{
    private const PER_PAGE = 12;

    public function index(Request $request): View
    {
        // An array such as ?search[]=x is ignored instead of crashing
        $search = is_string($request->query('search')) ? trim($request->query('search')) : '';
        $category = is_string($request->query('category')) ? $request->query('category') : '';
        $status = in_array($request->query('status'), ['published', 'draft'], true) ? $request->query('status') : null;
        $featured = $request->query('featured') === '1';

        $packages = PhotographyPackage::query()
            ->with('coverImage')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->when($status, fn ($query) => $query->where('is_published', $status === 'published'))
            ->when($featured, fn ($query) => $query->where('is_featured', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('owner.packages.index', [
            'packages' => $packages,
            // Per category, for the filter chips: always the whole catalog, not the current filters
            'categories' => PhotographyPackage::query()->selectRaw('category, COUNT(*) as total')->groupBy('category')->orderBy('category')->pluck('total', 'category'),
            'search' => $search,
            'category' => $category,
            'status' => $status,
            'featured' => $featured,
            'hasFilters' => $search !== '' || $category !== '' || $status !== null || $featured,
            'stats' => $this->catalogStats(),
        ]);
    }

    /**
     * Totals for the tiles above the list, in one query. Always the whole catalog, never the current search or filter;
     * soft-deleted packages are left out by the model's scope.
     *
     * @return array<string, mixed>
     */
    private function catalogStats(): array
    {
        $row = PhotographyPackage::query()
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(is_published), 0) as published, COALESCE(SUM(is_featured), 0) as featured, COUNT(price) as priced, AVG(price) as average_price, MIN(price) as min_price, MAX(price) as max_price')
            ->toBase()
            ->first();

        return [
            'total' => (int) $row->total,
            'published' => (int) $row->published,
            'unpublished' => (int) $row->total - (int) $row->published,
            'featured' => (int) $row->featured,
            'priced' => (int) $row->priced,
            'average' => $row->average_price,
            'min' => $row->min_price,
            'max' => $row->max_price,
        ];
    }

    public function create(): View
    {
        return view('owner.packages.create', $this->formData(new PhotographyPackage([
            'duration_minutes' => 60,
            'max_people' => 1,
            'inclusions' => [],
            'price_requires_confirmation' => false,
            'is_published' => false,
            'is_featured' => false,
            'sort_order' => 0,
        ]), []));
    }

    public function store(PackageRequest $request): RedirectResponse
    {
        $data = $this->attributes($request);
        $themeIds = $request->validated('theme_ids') ?? [];

        $package = DB::transaction(function () use ($data, $themeIds) {
            try {
                $package = PhotographyPackage::create($data);
            } catch (UniqueConstraintViolationException) {
                // Another request took the same address a moment ago: pick the next free one
                $package = PhotographyPackage::create(['slug' => PhotographyPackage::uniqueSlug($data['name'])] + $data);
            }

            $package->themes()->sync($themeIds);

            return $package;
        });

        return redirect()
            ->route('owner.packages.edit', $package)
            ->with('status', "\"{$package->name}\" was created. You can add photos below.");
    }

    public function edit(PhotographyPackage $package): View
    {
        $package->load('images');

        return view('owner.packages.edit', $this->formData($package, $package->themes()->pluck('themes.id')->all()));
    }

    public function update(PackageRequest $request, PhotographyPackage $package): RedirectResponse
    {
        $data = $this->attributes($request, $package);
        $themeIds = $request->validated('theme_ids') ?? [];

        try {
            DB::transaction(function () use ($package, $data, $themeIds) {
                $package->update($data);
                $package->themes()->sync($themeIds);
            });
        } catch (UniqueConstraintViolationException) {
            // Another request took this address between the check and the save
            return back()->withInput()->withErrors(['slug' => 'This web address was just taken. Choose another one.']);
        }

        return redirect()
            ->route('owner.packages.edit', $package)
            ->with('status', "\"{$package->name}\" was saved.");
    }

    public function destroy(PhotographyPackage $package): RedirectResponse
    {
        // Soft delete: the photos stay on disk with the package
        $package->delete();

        return redirect()
            ->route('owner.packages.index')
            ->with('status', "\"{$package->name}\" was deleted.");
    }

    public function publish(PhotographyPackage $package): RedirectResponse
    {
        $package->update(['is_published' => ! $package->is_published]);

        return back()->with('status', $package->is_published
            ? "\"{$package->name}\" is now published."
            : "\"{$package->name}\" is now unpublished.");
    }

    public function feature(PhotographyPackage $package): RedirectResponse
    {
        $package->update(['is_featured' => ! $package->is_featured]);

        return back()->with('status', $package->is_featured
            ? "\"{$package->name}\" is now featured."
            : "\"{$package->name}\" is no longer featured.");
    }

    /** @return array<string, mixed> */
    private function attributes(PackageRequest $request, ?PhotographyPackage $package = null): array
    {
        $data = collect($request->validated())->except('theme_ids')->all();

        // A blank address is generated from the name; on edit a blank keeps the current one, so links stay stable
        $data['slug'] = ($data['slug'] ?? null)
            ?: ($package?->slug ?? PhotographyPackage::uniqueSlug($data['name']));
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['inclusions'] = array_values($data['inclusions'] ?? []);

        return $data;
    }

    /**
     * @param  array<int, int>  $selectedThemeIds
     * @return array<string, mixed>
     */
    private function formData(PhotographyPackage $package, array $selectedThemeIds): array
    {
        return [
            'package' => $package,
            'categories' => PhotographyPackage::query()->distinct()->orderBy('category')->pluck('category'),
            'themes' => Theme::query()->orderBy('name')->get(['id', 'name', 'color_swatches', 'is_published']),
            'selectedThemeIds' => $selectedThemeIds,
        ];
    }
}
