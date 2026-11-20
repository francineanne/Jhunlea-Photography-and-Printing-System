<?php

namespace Tests\Feature\Polish;

use App\Models\PhotographyBooking;
use App\Models\PrintingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** Phase 10N: the visual polish that the server renders (the file picker, the tab bars, the record header). */
class UiPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_file_button_is_a_label_for_a_hidden_but_focusable_input(): void
    {
        $html = Blade::render('<div><input id="f" type="file" name="files[]" class="peer sr-only"><x-ui.file-button for="f" /></div>');

        $this->assertStringContainsString('<label for="f" aria-hidden="true" data-file-button', $html);
        $this->assertStringContainsString('peer-focus-visible:outline-2', $html);
        $this->assertStringContainsString('Choose files', $html);
        $this->assertStringContainsString('data-file-chosen data-empty="No files yet"', $html);
        $this->assertStringNotContainsString('data-file-chosen', Blade::render('<x-ui.file-button for="f" :chosen="false" />'));
    }

    public function test_the_printing_wizard_has_no_native_file_control_on_show(): void
    {
        PrintingService::factory()->published()->create();
        $html = $this->actingAs(User::factory()->create())->get(route('customer.printing.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input id="files" type="file"[^>]*class="peer sr-only"/s', $html);
        $this->assertStringContainsString('data-file-button', $html);
        // The old native style (file: utilities on a visible input) is gone
        $this->assertStringNotContainsString('file:bg-brand', $html);
    }

    public function test_the_tab_bars_scroll_without_a_scrollbar(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (['owner.bookings.index', 'owner.orders.index', 'owner.notifications.index'] as $route) {
            $this->actingAs($owner)->get(route($route))->assertOk()
                ->assertSee('scroll-x-fade', false)->assertSee('x-init="$scrollFade($el)"', false);
        }
    }

    public function test_the_booking_header_puts_the_message_button_under_the_tiles(): void
    {
        $owner = User::factory()->owner()->create();
        $booking = PhotographyBooking::factory()->create();

        $html = $this->actingAs($owner)->get(route('owner.bookings.show', $booking))->assertOk()->getContent();

        // The tiles, then the actions slot, in the same right-hand column
        $this->assertMatchesRegularExpression('/<\/dl>\s*<div class="flex" data-header-actions>/', $html);
    }
}
