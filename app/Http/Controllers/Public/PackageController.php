<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\PhotographyPackage;
use App\Models\Theme;
use App\Support\PackageFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PackageController extends Controller
{
    private const PER_PAGE = 9;

    private const RELATED_LIMIT = 3;

    public function index(Request $request): View|RedirectResponse
    {
        // Chip counts cover the whole published catalog, not the current filters
        $categories = PhotographyPackage::query()->published()->selectRaw('category, COUNT(*) as total')->groupBy('category')->orderBy('category')->pluck('total', 'category');

        $filters = PackageFilters::fromQuery($request->query());
        $filters = $filters->withCategory($categories->keys()->first(fn ($name) => strcasecmp($name, (string) $filters->category) === 0) ?? $filters->category);

        $packages = $filters->apply(PhotographyPackage::query()->published()->with('coverImage'))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // A page number past the end (an old link, a hand-edited address) goes to the last page instead of an empty one
        if ($packages->isEmpty() && $packages->total() > 0) {
            return redirect($packages->url($packages->lastPage()));
        }

        return view('public.packages.index', [
            'packages' => $packages,
            'filters' => $filters,
            'categories' => $categories,
            'themes' => Theme::query()->published()->whereHas('packages', fn ($query) => $query->published())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(string $slug): View
    {
        $package = PhotographyPackage::query()
            ->published()
            ->where('slug', $slug)
            ->with(['images', 'themes' => fn ($query) => $query->published()])
            ->firstOrFail();

        return view('public.packages.show', [
            'package' => $package,
            'related' => PhotographyPackage::query()->published()->with('coverImage')
                ->where('category', $package->category)->whereKeyNot($package->id)
                ->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('name')
                ->limit(self::RELATED_LIMIT)->get(),
        ]);
    }
}
