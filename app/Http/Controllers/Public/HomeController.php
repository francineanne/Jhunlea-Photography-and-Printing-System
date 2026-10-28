<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\PhotographyPackage;
use App\Models\PrintingService;
use Illuminate\View\View;

/** The landing page. Only published, non-deleted records are ever read here. */
class HomeController extends Controller
{
    private const FEATURED_LIMIT = 3;

    public function __invoke(): View
    {
        return view('public.home', [
            'serviceCategories' => PrintingService::query()->published()->selectRaw('category, COUNT(*) as total')->groupBy('category')->orderBy('category')->pluck('total', 'category'),
            'packageStats' => PhotographyPackage::query()->published()->toBase()->selectRaw('COUNT(*) as total, MIN(price) as lowest_price')->first(),
            'featured' => PhotographyPackage::query()->published()->featured()->with('coverImage')->orderBy('sort_order')->orderBy('name')->limit(self::FEATURED_LIMIT)->get(),
        ]);
    }
}
