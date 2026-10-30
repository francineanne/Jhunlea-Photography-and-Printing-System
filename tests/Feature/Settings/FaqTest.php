<?php

namespace Tests\Feature\Settings;

use App\Models\Faq;
use App\Models\User;
use App\Services\FaqService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 6B: the owner's FAQ editor and the public FAQ page. */
class FaqTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
    }

    private function add(string $question, bool $published = true, string $answer = 'An answer.'): Faq
    {
        return app(FaqService::class)->create(['question' => $question, 'answer' => $answer, 'is_published' => $published]);
    }

    /** @return list<string> the questions in position order, after checking the positions are 1..N */
    private function order(): array
    {
        $rows = Faq::query()->ordered()->get();
        $this->assertSame(range(1, max(1, $rows->count())), $rows->isEmpty() ? [1] : $rows->pluck('position')->all());

        return $rows->pluck('question')->all();
    }

    private function move(Faq $faq, string $direction, int|string $from)
    {
        return $this->actingAs($this->owner)->post(route('owner.faqs.move', $faq), ['direction' => $direction, 'from' => $from]);
    }

    public function test_owner_only_also_for_ids_that_do_not_exist(): void
    {
        $faq = $this->add('Do you print on weekends?');
        $customer = User::factory()->create();
        $routes = [
            ['get', route('owner.faqs.index')], ['get', route('owner.faqs.create')], ['post', route('owner.faqs.store')],
            ['get', route('owner.faqs.edit', $faq)], ['put', route('owner.faqs.update', $faq)], ['post', route('owner.faqs.publish', $faq)],
            ['post', route('owner.faqs.move', $faq)], ['get', route('owner.faqs.confirm-delete', $faq)], ['delete', route('owner.faqs.destroy', $faq)],
            ['get', '/owner/faqs/999/edit'], ['delete', '/owner/faqs/999'], ['post', '/owner/faqs/999/move'],
        ];

        foreach ($routes as [$method, $url]) {
            $this->actingAs($customer)->{$method}($url, ['question' => 'X', 'answer' => 'Y', 'confirm' => '1', 'direction' => 'up', 'from' => 1, 'published' => '0'])->assertForbidden();
        }
        auth()->logout();
        foreach ($routes as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }
        $this->assertSame(['Do you print on weekends?'], $this->order());
        $this->assertTrue($faq->fresh()->is_published);
    }

    public function test_create_edit_and_the_editor_list(): void
    {
        $this->actingAs($this->owner)->get(route('owner.faqs.index'))->assertOk()->assertSee('No questions yet');

        $this->actingAs($this->owner)->post(route('owner.faqs.store'), ['question' => '  How long does printing take?  ', 'answer' => "Usually one day.\r\nRush jobs: ask us.", 'is_published' => '1'])
            ->assertRedirect(route('owner.faqs.index'))->assertSessionHas('status');
        $this->actingAs($this->owner)->post(route('owner.faqs.store'), ['question' => 'Can I bring my own props?', 'answer' => 'Yes.', 'is_published' => '0']);

        $first = Faq::query()->where('position', 1)->first();
        $this->assertSame('How long does printing take?', $first->question);
        $this->assertSame("Usually one day.\nRush jobs: ask us.", $first->answer);
        $this->assertFalse(Faq::query()->where('position', 2)->value('is_published'));

        $this->actingAs($this->owner)->put(route('owner.faqs.update', $first), ['question' => 'How long does printing usually take?', 'answer' => 'One day.', 'is_published' => '1'])->assertRedirect(route('owner.faqs.index'));
        $this->assertSame(['How long does printing usually take?', 'Can I bring my own props?'], $this->order());

        $this->actingAs($this->owner)->get(route('owner.faqs.index'))->assertOk()->assertSeeInOrder(['How long does printing usually take?', 'Shown', 'Can I bring my own props?', 'Hidden'])
            ->assertSee('2 of 50 questions · 1 shown');
        $this->actingAs($this->owner)->get(route('owner.faqs.edit', $first))->assertOk()->assertSee('value="How long does printing usually take?"', false);
    }

    public function test_bad_input_is_refused_with_messages(): void
    {
        $this->add('Where are you?');
        $cases = [
            [['question' => '', 'answer' => 'A'], 'question'],
            [['question' => str_repeat('q', 201), 'answer' => 'A'], 'question'],
            [['question' => 'Q', 'answer' => ''], 'answer'],
            [['question' => 'Q', 'answer' => str_repeat('a', 2001)], 'answer'],
            [['question' => "Q\x07", 'answer' => 'A'], 'question'],
            [['question' => ['Q'], 'answer' => 'A'], 'question'],
            [['question' => 'WHERE ARE YOU?', 'answer' => 'A'], 'question'],
        ];

        foreach ($cases as [$data, $field]) {
            $this->actingAs($this->owner)->post(route('owner.faqs.store'), $data)->assertRedirect(route('owner.faqs.create'))->assertSessionHasErrors($field);
        }
        $this->actingAs($this->owner)->post(route('owner.faqs.store'), ['question' => 'where are you?', 'answer' => 'A'])->assertSessionHasErrors(['question' => FaqService::DUPLICATE_MESSAGE]);
        $this->assertSame(['Where are you?'], $this->order());

        // Exactly at the limits is fine; editing a question to another one's text is refused too
        $this->actingAs($this->owner)->post(route('owner.faqs.store'), ['question' => str_repeat('q', 200), 'answer' => str_repeat('a', 2000)])->assertSessionHasNoErrors();
        $long = Faq::query()->where('position', 2)->first();
        $this->actingAs($this->owner)->put(route('owner.faqs.update', $long), ['question' => 'Where Are You?', 'answer' => 'A'])->assertSessionHasErrors('question');
        $this->actingAs($this->owner)->put(route('owner.faqs.update', $long), ['question' => str_repeat('q', 200), 'answer' => 'Shorter'])->assertSessionHasNoErrors();
    }

    public function test_at_most_fifty_questions(): void
    {
        foreach (range(1, Faq::MAX_ITEMS) as $i) {
            $this->add("Question {$i}?");
        }

        $this->actingAs($this->owner)->post(route('owner.faqs.store'), ['question' => 'One more?', 'answer' => 'A'])->assertSessionHasErrors('question');
        $this->actingAs($this->owner)->get(route('owner.faqs.create'))->assertRedirect(route('owner.faqs.index'));
        $this->actingAs($this->owner)->get(route('owner.faqs.index'))->assertOk()->assertDontSee(route('owner.faqs.create'));
        $this->assertSame(Faq::MAX_ITEMS, Faq::query()->count());
    }

    public function test_moving_up_and_down_with_edges_and_double_clicks(): void
    {
        [$a, $b, $c] = [$this->add('A?'), $this->add('B?'), $this->add('C?')];

        $this->move($c, 'up', 3)->assertSessionHas('status', 'Order saved.');
        $this->assertSame(['A?', 'C?', 'B?'], $this->order());

        // The same click again (the page still said position 3): nothing moves
        $this->move($c, 'up', 3)->assertSessionHas('status', 'Nothing moved: the list had already changed.');
        $this->assertSame(['A?', 'C?', 'B?'], $this->order());

        // The first cannot move up and the last cannot move down
        $this->move($a, 'up', 1);
        $this->move($b, 'down', 3);
        $this->assertSame(['A?', 'C?', 'B?'], $this->order());

        $this->move($a, 'down', 1);
        $this->assertSame(['C?', 'A?', 'B?'], $this->order());

        $this->move($a, 'sideways', 2)->assertSessionHasErrors('direction');
        $this->move($a, 'up', 'x')->assertSessionHasErrors('from');
        $this->actingAs($this->owner)->post('/owner/faqs/999/move', ['direction' => 'up', 'from' => 2])->assertSessionHas('status', 'Nothing moved: the list had already changed.');
        $this->assertSame(['C?', 'A?', 'B?'], $this->order());

        $html = $this->actingAs($this->owner)->get(route('owner.faqs.index'))->getContent();
        $this->assertSame(2, substr_count($html, 'type="submit" disabled'), 'only the first up and the last down are disabled');
    }

    public function test_publish_and_hide_and_delete_keep_positions_consecutive(): void
    {
        [$a, $b, $c, $d] = [$this->add('A?'), $this->add('B?'), $this->add('C?'), $this->add('D?')];

        $this->actingAs($this->owner)->post(route('owner.faqs.publish', $b), ['published' => '0'])->assertSessionHas('status');
        $this->actingAs($this->owner)->post(route('owner.faqs.publish', $b), ['published' => '0']);
        $this->assertFalse($b->fresh()->is_published);
        $this->actingAs($this->owner)->post(route('owner.faqs.publish', $b), ['published' => 'maybe'])->assertSessionHasErrors('published');
        $this->assertFalse($b->fresh()->is_published);

        // Delete needs the confirm step; the confirm page works without JavaScript
        $this->actingAs($this->owner)->get(route('owner.faqs.confirm-delete', $b))->assertOk()->assertSee('Delete this question?')->assertSee('B?');
        $this->actingAs($this->owner)->delete(route('owner.faqs.destroy', $b))->assertSessionHasErrors('confirm');
        $this->assertSame(4, Faq::query()->count());

        $this->actingAs($this->owner)->delete(route('owner.faqs.destroy', $b), ['confirm' => '1'])->assertSessionHas('status', 'Question deleted.');
        $this->actingAs($this->owner)->delete(route('owner.faqs.destroy', $b), ['confirm' => '1'])->assertSessionHas('status', 'That question was already deleted.');
        $this->assertSame(['A?', 'C?', 'D?'], $this->order());

        $this->actingAs($this->owner)->delete(route('owner.faqs.destroy', $a), ['confirm' => '1']);
        $this->assertSame(['C?', 'D?'], $this->order());
        $this->add('E?');
        $this->assertSame(['C?', 'D?', 'E?'], $this->order());
    }

    public function test_mass_assignment_cannot_set_the_position(): void
    {
        $this->add('A?');
        $this->actingAs($this->owner)->post(route('owner.faqs.store'), ['question' => 'B?', 'answer' => 'B', 'position' => 1, 'id' => 77, 'created_at' => '2000-01-01']);
        $b = Faq::query()->where('question', 'B?')->first();
        $this->actingAs($this->owner)->put(route('owner.faqs.update', $b), ['question' => 'B?', 'answer' => 'B', 'position' => 1]);

        $this->assertSame(2, $b->fresh()->position);
        $this->assertNotSame(77, $b->id);
        $this->assertNotSame('2000', $b->created_at->format('Y'));
        $this->assertNotContains('position', (new Faq)->getFillable());
        $this->assertSame(['A?', 'B?'], $this->order());
    }

    public function test_the_public_page_shows_only_published_items_in_order(): void
    {
        $this->get(route('faq'))->assertOk()->assertSee('No questions here yet')->assertSee('<meta name="robots" content="noindex', false);

        $this->add('Second shown?', true, "Line one\nLine two");
        $this->add('Secret hidden question?', false, 'Hidden answer text');
        $first = $this->add('First shown?');
        $this->move($first, 'up', 3);
        $this->move($first, 'up', 2);
        auth()->logout();

        $html = $this->get(route('faq'))->assertOk()
            ->assertSeeInOrder(['First shown?', 'Second shown?'])->assertSee("Line one\nLine two")
            ->assertDontSee('Secret hidden question?')->assertDontSee('Hidden answer text')
            ->assertSee('<title>Frequently asked questions', false)->assertSee('<meta name="description"', false)
            ->getContent();
        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('whitespace-pre-line', $html);
    }

    public function test_hostile_text_is_escaped_on_the_editor_and_the_public_page(): void
    {
        $this->add('<script>alert(1)</script>?', true, '<img src=x onerror=alert(2)>');

        $this->actingAs($this->owner)->get(route('owner.faqs.index'))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false);
        $faq = Faq::query()->first();
        $this->actingAs($this->owner)->get(route('owner.faqs.edit', $faq))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        auth()->logout();
        $this->get(route('faq'))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false)->assertSee('&lt;img src=x onerror=alert(2)&gt;', false);
    }

    public function test_the_footer_shows_faq_only_when_something_is_published(): void
    {
        $this->get(route('home'))->assertOk()->assertDontSee('href="'.route('faq').'"', false);

        $faq = $this->add('Hidden one?', false);
        $this->get(route('home'))->assertDontSee('href="'.route('faq').'"', false);

        app(FaqService::class)->setPublished($faq, true);
        $this->get(route('home'))->assertSee('href="'.route('faq').'"', false);
        $this->get(route('services.index'))->assertSee('href="'.route('faq').'"', false);
    }

    public function test_the_settings_page_counts_the_questions_and_marks_the_nav(): void
    {
        $this->add('A?');
        $this->add('B?', false);

        $this->actingAs($this->owner)->get(route('owner.settings.index'))->assertOk()->assertSee('1 question shown on the public FAQ page, 1 hidden')->assertSee(route('owner.faqs.index'));
        $html = $this->actingAs($this->owner)->get(route('owner.faqs.index'))->getContent();
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('owner.settings.index'), '/').'"\s+aria-current="page"/', $html);
    }
}
