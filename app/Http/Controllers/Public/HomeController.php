<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\PhotographyPackage;
use App\Models\PrintingService;
use App\Support\ShopSettings;
use Illuminate\View\View;

/** The landing page. Only published, non-deleted records are ever read here. */
class HomeController extends Controller
{
    private const FEATURED_LIMIT = 3;

    private const FAQ_LIMIT = 6;

    public function __invoke(): View
    {
        // Phase 10D: one more than is shown, to know whether "See all questions" is needed. rescue(): the site keeps
        // working before the faqs table exists. The footer's FAQ link reuses the answer instead of asking again
        $faqs = rescue(fn () => Faq::query()->published()->ordered()->limit(self::FAQ_LIMIT + 1)->get(['id', 'question', 'answer']), collect(), false);
        view()->share('hasFaqKnown', $faqs->isNotEmpty());

        return view('public.home', [
            'serviceCategories' => PrintingService::query()->published()->selectRaw('category, COUNT(*) as total')->groupBy('category')->orderBy('category')->pluck('total', 'category'),
            'packageStats' => PhotographyPackage::query()->published()->toBase()->selectRaw('COUNT(*) as total, MIN(price) as lowest_price')->first(),
            'featured' => PhotographyPackage::query()->published()->featured()->with('coverImage')->orderBy('sort_order')->orderBy('name')->limit(self::FEATURED_LIMIT)->get(),
            // Phase 10D: the first published questions (the FAQ page has them all) and the map of the shop's address
            'faqs' => $faqs->take(self::FAQ_LIMIT),
            'moreFaqs' => $faqs->count() > self::FAQ_LIMIT,
            'map' => app(ShopSettings::class)->map(),
            'shop' => app(ShopSettings::class)->business(),
        ]);
    }
}
