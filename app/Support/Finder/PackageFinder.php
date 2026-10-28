<?php

namespace App\Support\Finder;

use App\Models\PhotographyPackage;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Ranks published packages against the answers of the package finder.
 *
 * Plain, fixed rules, so the same answers always give the same order and every result can say why it matched.
 * Only real package fields are used. Points (out of the questions that were answered):
 *   occasion  40  the package category is the one chosen
 *   people    25  the package takes at least that many people
 *   budget    20  the listed price is within the budget
 *             8   no listed price: the owner confirms it, so it may fit (shown as such, never as a match on price)
 *   backdrop  15  a published theme chosen is linked to the package
 * Ties go to featured packages, then the lower listed price (no price last), then the name.
 */
class PackageFinder
{
    public const POINTS = ['category' => 40, 'people' => 25, 'budget' => 20, 'theme' => 15];

    public const ON_REQUEST_BUDGET_POINTS = 8;

    /**
     * @param  Collection<int, PhotographyPackage>  $packages  published packages with their published themes loaded
     * @return Collection<int, FinderResult>
     */
    public static function rank(Collection $packages, FinderAnswers $answers, ?string $themeName = null): Collection
    {
        $max = ($answers->category !== null ? self::POINTS['category'] : 0)
            + ($answers->people !== null ? self::POINTS['people'] : 0)
            + ($answers->budget !== null ? self::POINTS['budget'] : 0)
            + ($answers->theme !== null ? self::POINTS['theme'] : 0);

        return $packages
            ->map(fn (PhotographyPackage $package) => self::score($package, $answers, $max, $themeName))
            ->sort(function (FinderResult $a, FinderResult $b) {
                return [$b->score, (int) $b->package->is_featured, $a->package->price === null, (float) $a->package->price, $a->package->name, $a->package->id]
                    <=> [$a->score, (int) $a->package->is_featured, $b->package->price === null, (float) $b->package->price, $b->package->name, $b->package->id];
            })
            ->values();
    }

    private static function score(PhotographyPackage $package, FinderAnswers $answers, int $max, ?string $themeName): FinderResult
    {
        $score = 0;
        $reasons = [];
        $misses = [];

        if ($answers->category !== null) {
            if ($package->category === $answers->category) {
                $score += self::POINTS['category'];
                $reasons[] = "Made for your occasion: {$package->category}";
            } else {
                $misses[] = "It is a {$package->category} package, not {$answers->category}";
            }
        }

        if ($answers->people !== null) {
            if ($package->max_people >= $answers->people) {
                $score += self::POINTS['people'];
                $reasons[] = "Fits {$answers->people} ".($answers->people === 1 ? 'person' : 'people')." (up to {$package->max_people})";
            } else {
                $misses[] = "Fits up to {$package->max_people} ".($package->max_people === 1 ? 'person' : 'people');
            }
        }

        if ($answers->budget !== null) {
            if ($package->price === null) {
                $score += self::ON_REQUEST_BUDGET_POINTS;
                $reasons[] = 'The price is confirmed on request, so it may fit your budget';
            } elseif ((float) $package->price <= $answers->budget) {
                $score += self::POINTS['budget'];
                $reasons[] = $package->price_requires_confirmation
                    ? 'Starts within your budget (from '.Money::format($package->price).', final price confirmed after review)'
                    : 'Within your budget ('.Money::format($package->price).')';
            } else {
                $misses[] = 'Above your budget ('.Money::format($package->price).')';
            }
        }

        if ($answers->theme !== null) {
            if ($package->themes->contains('id', $answers->theme)) {
                $score += self::POINTS['theme'];
                $reasons[] = 'Offers the '.($themeName ?? $package->themes->firstWhere('id', $answers->theme)?->name ?? 'backdrop you chose').' backdrop';
            } else {
                $misses[] = 'The backdrop you chose is not offered with it';
            }
        }

        return new FinderResult($package, $score, $max, $reasons, $misses);
    }
}
