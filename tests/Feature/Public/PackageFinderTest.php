<?php

namespace Tests\Feature\Public;

use App\Models\PhotographyPackage;
use App\Models\PrintingService;
use App\Models\Theme;
use App\Support\Finder\FinderAnswers;
use App\Support\Finder\PackageFinder;
use App\Support\PackageFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PackageFinderTest extends TestCase
{
    use RefreshDatabase;

    private function package(string $name, array $attributes = []): PhotographyPackage
    {
        return PhotographyPackage::factory()->published()->create($attributes + ['name' => $name, 'category' => 'Family', 'max_people' => 4, 'price' => '2000.00', 'is_featured' => false]);
    }

    private function rank(Collection $packages, FinderAnswers $answers): Collection
    {
        return PackageFinder::rank($packages->each->load('themes'), $answers);
    }

    public function test_answers_read_valid_values_and_ignore_the_rest(): void
    {
        $answers = FinderAnswers::fromQuery(['category' => ' Family ', 'people' => '4', 'budget' => '2500.50', 'theme' => '7']);

        $this->assertSame('Family', $answers->category);
        $this->assertSame(4, $answers->people);
        $this->assertSame(2500.5, $answers->budget);
        $this->assertSame(7, $answers->theme);

        $bad = FinderAnswers::fromQuery(['category' => ['x'], 'people' => '0', 'budget' => '-5', 'theme' => 'abc']);
        $this->assertTrue($bad->isEmpty());
        $this->assertTrue(FinderAnswers::fromQuery(['people' => '501', 'budget' => '1e9', 'theme' => '0'])->isEmpty());
        $this->assertTrue(FinderAnswers::fromQuery([])->isEmpty());
    }

    public function test_a_package_that_matches_everything_scores_full_marks_with_reasons(): void
    {
        $theme = Theme::factory()->published()->create(['name' => 'Garden']);
        $package = $this->package('Perfect');
        $package->themes()->attach($theme);

        $result = $this->rank(collect([$package]), new FinderAnswers('Family', 3, 2500.0, $theme->id))->first();

        $this->assertSame(100, $result->score);
        $this->assertSame(100, $result->maxScore);
        $this->assertSame(100, $result->percent());
        $this->assertTrue($result->isFullMatch());
        $this->assertCount(4, $result->reasons);
        $this->assertSame([], $result->misses);
        $this->assertStringContainsString('Garden', implode(' ', $result->reasons));
    }

    public function test_the_percent_is_out_of_the_questions_that_were_answered(): void
    {
        $match = $this->package('Match');
        $miss = $this->package('Miss', ['category' => 'Portrait']);

        $ranked = $this->rank(collect([$miss, $match]), new FinderAnswers(category: 'Family'));

        $this->assertSame(['Match', 'Miss'], $ranked->pluck('package.name')->all());
        $this->assertSame([100, 0], $ranked->map->percent()->all());
        $this->assertSame(['It is a Portrait package, not Family'], $ranked->last()->misses);
    }

    public function test_nothing_answered_gives_no_percent_and_no_full_match(): void
    {
        $result = $this->rank(collect([$this->package('Any')]), new FinderAnswers)->first();

        $this->assertNull($result->percent());
        $this->assertFalse($result->isFullMatch());
        $this->assertSame([], $result->reasons);
    }

    public function test_the_budget_edge_is_included_and_one_peso_over_is_not(): void
    {
        $edge = $this->package('Edge', ['price' => '2000.00']);
        $over = $this->package('Over', ['price' => '2000.01']);

        $ranked = $this->rank(collect([$over, $edge]), new FinderAnswers(budget: 2000.0));

        $this->assertSame(['Edge', 'Over'], $ranked->pluck('package.name')->all());
        $this->assertSame(100, $ranked->first()->percent());
        $this->assertSame(0, $ranked->last()->score);
        $this->assertStringContainsString('Above your budget', $ranked->last()->misses[0]);
    }

    public function test_a_package_with_no_price_gets_fewer_points_and_is_never_called_a_price_match(): void
    {
        $priced = $this->package('Priced', ['price' => '1500.00']);
        $asked = $this->package('Asked', ['price' => null]);

        $ranked = $this->rank(collect([$asked, $priced]), new FinderAnswers(budget: 2000.0));

        $this->assertSame(['Priced', 'Asked'], $ranked->pluck('package.name')->all());
        $this->assertSame(PackageFinder::ON_REQUEST_BUDGET_POINTS, $ranked->last()->score);
        $this->assertStringContainsString('confirmed on request', $ranked->last()->reasons[0]);
        $this->assertFalse($ranked->last()->isFullMatch());
    }

    public function test_the_people_count_edge_is_included(): void
    {
        $exact = $this->package('Exact', ['max_people' => 5]);
        $short = $this->package('Short', ['max_people' => 4]);

        $ranked = $this->rank(collect([$short, $exact]), new FinderAnswers(people: 5));

        $this->assertSame(['Exact', 'Short'], $ranked->pluck('package.name')->all());
        $this->assertSame([25, 0], $ranked->pluck('score')->all());
    }

    public function test_a_backdrop_matches_only_when_the_package_offers_it(): void
    {
        $theme = Theme::factory()->published()->create(['name' => 'Studio']);
        $with = $this->package('With');
        $with->themes()->attach($theme);
        $without = $this->package('Without');

        $ranked = $this->rank(collect([$without, $with]), new FinderAnswers(theme: $theme->id));

        $this->assertSame(['With', 'Without'], $ranked->pluck('package.name')->all());
        $this->assertSame(['The backdrop you chose is not offered with it'], $ranked->last()->misses);
    }

    public function test_ties_go_to_featured_then_lower_price_then_no_price_last_then_name(): void
    {
        $plainCheap = $this->package('Plain Cheap', ['price' => '1000.00']);
        $plainDear = $this->package('Plain Dear', ['price' => '3000.00']);
        $plainNone = $this->package('Plain None', ['price' => null]);
        $star = $this->package('Star', ['price' => '5000.00', 'is_featured' => true]);
        $tieA = $this->package('Aaa Tie', ['price' => '1000.00']);

        $ranked = $this->rank(collect([$plainNone, $plainDear, $tieA, $plainCheap, $star]), new FinderAnswers(category: 'Family'));

        $this->assertSame(['Star', 'Aaa Tie', 'Plain Cheap', 'Plain Dear', 'Plain None'], $ranked->pluck('package.name')->all());
    }

    public function test_the_same_answers_always_give_the_same_order(): void
    {
        $packages = collect([$this->package('B'), $this->package('A'), $this->package('C', ['price' => null])]);
        $answers = new FinderAnswers('Family', 2, 3000.0);

        $first = $this->rank($packages, $answers)->pluck('package.id')->all();
        $second = $this->rank($packages->reverse()->values(), $answers)->pluck('package.id')->all();

        $this->assertSame($first, $second);
    }

    public function test_no_matches_shows_a_friendly_result_not_an_error(): void
    {
        $this->package('Family One');

        $response = $this->get('/packages/finder?category=Nothing+Like+This&people=2')->assertOk();

        $this->assertNotNull($response->viewData('results'));
    }

    public function test_the_finder_page_only_ranks_published_packages(): void
    {
        $this->package('Shown');
        PhotographyPackage::factory()->create(['name' => 'Hidden Draft', 'category' => 'Family']);
        $this->package('Gone')->delete();

        $response = $this->get('/packages/finder?category=Family')->assertOk();

        $this->assertSame(['Shown'], $response->viewData('results')->pluck('package.name')->all());
        $response->assertDontSee('Hidden Draft');
    }

    public function test_the_page_shows_the_questions_and_no_results_before_anything_is_answered(): void
    {
        $this->package('One');

        $this->get('/packages/finder')->assertOk()->assertSee('1. What is the occasion?')->assertDontSee('Why this fits your answers')->assertDontSee('noindex', false);
    }

    public function test_the_page_shows_the_match_percent_and_the_reasons(): void
    {
        $this->package('Great Fit');

        $this->get('/packages/finder?category=Family&people=3&budget=2500')->assertOk()
            ->assertSee('Great Fit')->assertSee('100% match')->assertSee('Why this fits your answers')
            ->assertSee('Made for your occasion: Family')->assertSee('Within your budget')
            ->assertSee('A suggestion is not a booking or a quotation.');
    }

    public function test_when_nothing_matches_every_answer_the_closest_are_shown_with_what_does_not_match(): void
    {
        $this->package('Close One', ['max_people' => 2]);

        $this->get('/packages/finder?category=Family&people=9')->assertOk()
            ->assertSee('No package matches every answer')->assertSee('Close One')->assertSee('What does not match')->assertSee('Fits up to 2 people');
    }

    public function test_a_friendly_message_shows_when_no_package_matches_at_all(): void
    {
        $this->package('Only Family');

        $this->get('/packages/finder?category=Wedding&people=9')->assertOk()->assertSee('No package matches those answers')->assertDontSee('Only Family');
    }

    // ---- filters --------------------------------------------------------

    public function test_the_filters_keep_valid_values_and_drop_invalid_ones(): void
    {
        $filters = PackageFilters::fromQuery(['category' => 'Family', 'people' => '4', 'budget' => '3000', 'minutes' => '60', 'sort' => 'price_asc', 'theme' => '2']);

        $this->assertTrue($filters->isActive());
        $this->assertSame('price_asc', $filters->toQuery()['sort']);

        $junk = PackageFilters::fromQuery(['category' => ['a'], 'people' => 'x', 'budget' => '-1', 'minutes' => '0', 'sort' => 'nope', 'theme' => '-3']);
        $this->assertFalse($junk->isActive());
        $this->assertSame([], $junk->toQuery());
    }

    // ---- service price text ---------------------------------------------

    public function test_a_service_says_how_it_is_priced(): void
    {
        $this->assertNotSame('', PrintingService::factory()->published()->make()->priceText());
    }
}
