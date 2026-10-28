<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\PhotographyPackage;
use App\Models\Theme;
use App\Support\Finder\FinderAnswers;
use App\Support\Finder\PackageFinder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PackageFinderController extends Controller
{
    private const RESULT_LIMIT = 4;

    public function __invoke(Request $request): View
    {
        $answers = FinderAnswers::fromQuery($request->query());
        $categories = PhotographyPackage::query()->published()->orderBy('category')->distinct()->pluck('category');
        $answers = $answers->withCategory($categories->first(fn ($name) => strcasecmp($name, (string) $answers->category) === 0) ?? $answers->category);
        $themes = Theme::query()->published()->whereHas('packages', fn ($query) => $query->published())->orderBy('name')->get(['id', 'name']);

        $results = collect();

        if (! $answers->isEmpty()) {
            $packages = PhotographyPackage::query()->published()
                ->with(['coverImage', 'themes' => fn ($query) => $query->published()])
                ->get();

            // Full matches first; with none, the closest options that match at least one answer
            $ranked = PackageFinder::rank($packages, $answers, $themes->firstWhere('id', $answers->theme)?->name);
            $full = $ranked->filter->isFullMatch();
            $results = ($full->isNotEmpty() ? $full : $ranked->filter(fn ($result) => $result->score > 0))->take(self::RESULT_LIMIT)->values();
        }

        return view('public.packages.finder', [
            'answers' => $answers,
            'asked' => ! $answers->isEmpty(),
            'results' => $results,
            'exact' => $results->isNotEmpty() && $results->first()->isFullMatch(),
            'categories' => $categories,
            'themes' => $themes,
        ]);
    }
}
