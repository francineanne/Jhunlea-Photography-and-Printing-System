<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\PhotographyPackage;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PackageCompareController extends Controller
{
    public const MIN = 2;

    public const MAX = 3;

    public function __invoke(Request $request): View
    {
        $asked = $request->query('packages');
        $slugs = [];
        $notes = [];

        // Only well-formed addresses are looked up, each once, in the order given. Anything else is dropped.
        foreach (is_array($asked) ? $asked : [] as $slug) {
            if (is_string($slug) && strlen($slug) <= 150 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) && ! in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        $slugs = array_slice($slugs, 0, 20);

        $found = PhotographyPackage::query()->published()
            ->whereIn('slug', $slugs)
            ->with(['coverImage', 'themes' => fn ($query) => $query->published()])
            ->get()
            ->sortBy(fn ($package) => array_search($package->slug, $slugs, true))
            ->values();

        if ($slugs !== [] && $found->count() < count($slugs)) {
            $notes[] = 'Some packages you chose are not available any more and were left out.';
        }

        if ($found->count() > self::MAX) {
            $found = $found->take(self::MAX);
            $notes[] = 'You can compare at most '.self::MAX.' packages, so only the first '.self::MAX.' are shown.';
        }

        return view('public.packages.compare', [
            'packages' => $found,
            'ready' => $found->count() >= self::MIN,
            'notes' => $notes,
            'differencesOnly' => $request->query('differences') === '1',
            'min' => self::MIN,
            'max' => self::MAX,
        ]);
    }
}
