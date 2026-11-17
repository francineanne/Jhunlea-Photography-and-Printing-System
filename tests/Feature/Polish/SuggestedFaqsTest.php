<?php

namespace Tests\Feature\Polish;

use App\Models\Faq;
use App\Models\User;
use App\Support\FaqDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 10D: "Add suggested questions" in the FAQ editor. */
class SuggestedFaqsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_drafts_are_added_hidden_and_never_twice(): void
    {
        $owner = User::factory()->owner()->create();
        $count = count(app(FaqDrafts::class)->all());
        $this->assertGreaterThanOrEqual(15, $count);

        $this->actingAs($owner)->post(route('owner.faqs.suggested'))->assertRedirect(route('owner.faqs.index'))->assertSessionHas('status');

        $this->assertSame($count, Faq::query()->count());
        $this->assertSame(0, Faq::query()->where('is_published', true)->count(), 'Drafts are never published by themselves');
        $this->assertSame(range(1, $count), Faq::query()->orderBy('position')->pluck('position')->all());
        $this->get('/faq')->assertOk()->assertDontSee('Do I need an account to order or book?');

        // A second press adds nothing
        $this->actingAs($owner)->post(route('owner.faqs.suggested'))->assertRedirect(route('owner.faqs.index'));
        $this->assertSame($count, Faq::query()->count());
    }

    public function test_the_drafts_use_the_current_settings_and_respect_the_limits(): void
    {
        DB::table('shop_settings')->insert(['id' => 1, 'downpayment_percent' => 30, 'prepay_from' => '500.00', 'created_at' => now(), 'updated_at' => now()]);

        $drafts = collect(app(FaqDrafts::class)->all());
        $text = $drafts->pluck('answer')->implode(' ');

        $this->assertStringContainsString('30%', $text);
        $this->assertStringContainsString('₱500.00', $text);
        $this->assertSame($drafts->count(), $drafts->pluck('question')->map(fn ($q) => mb_strtolower($q))->unique()->count());

        foreach ($drafts as $draft) {
            $this->assertLessThanOrEqual(Faq::QUESTION_MAX, mb_strlen($draft['question']));
            $this->assertLessThanOrEqual(Faq::ANSWER_MAX, mb_strlen($draft['answer']));
            // The money wording rules hold in every draft
            $this->assertDoesNotMatchRegularExpression('/\b(revenue|sales|profit)\b/i', $draft['answer']);
        }
    }

    public function test_only_the_owner_may_add_them(): void
    {
        $this->post(route('owner.faqs.suggested'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->post(route('owner.faqs.suggested'))->assertForbidden();
        $this->assertSame(0, Faq::query()->count());
    }
}
