<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UiComponentsTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function components(): array
    {
        return [
            'logo' => ['<x-ui.logo />'],
            'icon' => ['<x-ui.icon name="photo_camera" />'],
            'button' => ['<x-ui.button variant="danger" size="sm">Delete</x-ui.button>'],
            'button as link' => ['<x-ui.button href="/somewhere">Go</x-ui.button>'],
            'button loading' => ['<x-ui.button :loading="true">Saving</x-ui.button>'],
            'input' => ['<x-ui.input label="Name" name="name" hint="Hint" icon="person" />'],
            'input with error' => ['<x-ui.input label="Name" name="name" error="Required" />'],
            'password input' => ['<x-ui.input label="Password" name="password" type="password" />'],
            'select' => ['<x-ui.select label="Pick" name="pick"><option>A</option></x-ui.select>'],
            'textarea' => ['<x-ui.textarea label="Notes" name="notes">Text</x-ui.textarea>'],
            'checkbox' => ['<x-ui.checkbox label="Agree" name="agree" />'],
            'badge' => ['<x-ui.badge tone="green">Okay</x-ui.badge>'],
            'status-badge' => ['<x-ui.status-badge status="under_review" />'],
            'card' => ["<x-ui.card :hover=\"true\">\n<x-slot:image><img alt=\"\"></x-slot:image>\nBody\n</x-ui.card>"],
            'notice' => ['<x-ui.notice>Heads up</x-ui.notice>'],
            'payment notice' => ['<x-ui.notice variant="payment" />'],
            'modal' => ['<x-ui.modal name="demo" title="Title">Body<x-slot:footer>Footer</x-slot:footer></x-ui.modal>'],
            'toast' => ['<x-ui.toast />'],
            'empty-state' => ['<x-ui.empty-state title="Nothing" description="Nothing yet"><a href="/">Add</a></x-ui.empty-state>'],
            'stepper' => ['<x-ui.stepper :steps="[\'One\', \'Two\', \'Three\']" :current="2" />'],
            'timeline' => ['<x-ui.timeline :events="[[\'title\' => \'Submitted\', \'at\' => \'Today\'], [\'title\' => \'Accepted\']]" />'],
            'aperture-spinner' => ['<x-ui.aperture-spinner />'],
            'page-loader' => ['<x-ui.page-loader />'],
        ];
    }

    #[DataProvider('components')]
    public function test_component_renders(string $template): void
    {
        $html = Blade::render($template);

        $this->assertNotEmpty(trim($html));
    }

    public function test_payment_notice_has_the_exact_policy_text(): void
    {
        $html = Blade::render('<x-ui.notice variant="payment" />');

        $this->assertStringContainsString(
            'Payment is made face-to-face at Jhunlea Photography &amp; Printing. No online checkout.',
            $html
        );
    }

    public function test_input_shows_its_error_and_marks_itself_invalid(): void
    {
        $html = Blade::render('<x-ui.input label="Name" name="name" error="Name is required" />');

        $this->assertStringContainsString('Name is required', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
    }

    public function test_timeline_with_no_events_renders_nothing(): void
    {
        $this->assertSame('', trim(Blade::render('<x-ui.timeline :events="[]" />')));
    }

    /** @return array<string, array{string, string}> */
    public static function statuses(): array
    {
        $expected = [
            'amber' => ['submitted', 'under_review', 'awaiting_customer_info'],
            'blue' => ['awaiting_schedule_confirmation', 'reschedule_proposed', 'partially_paid'],
            'violet' => ['processing'],
            'green' => ['accepted', 'confirmed', 'ready_for_pickup', 'paid'],
            'gray' => ['completed', 'photoshoot_completed', 'cancelled'],
            'red' => ['rejected', 'unpaid'],
        ];

        $cases = [];
        foreach ($expected as $tone => $statuses) {
            foreach ($statuses as $status) {
                $cases[$status] = [$status, $tone];
            }
        }

        return $cases;
    }

    #[DataProvider('statuses')]
    public function test_status_badge_maps_every_status_to_its_tone(string $status, string $tone): void
    {
        $html = Blade::render('<x-ui.status-badge :status="$status" />', ['status' => $status]);

        $this->assertStringContainsString("text-status-{$tone}", $html);
    }

    public function test_status_badge_covers_every_status_in_agents_md(): void
    {
        $documented = [
            // printing order
            'submitted', 'under_review', 'accepted', 'rejected', 'processing', 'ready_for_pickup', 'completed', 'cancelled',
            // photography booking
            'awaiting_customer_info', 'awaiting_schedule_confirmation', 'confirmed', 'reschedule_proposed', 'photoshoot_completed',
            // payment
            'unpaid', 'partially_paid', 'paid',
        ];

        $this->assertEqualsCanonicalizing($documented, array_keys(self::statuses()));
    }

    public function test_status_badge_falls_back_to_gray_for_unknown_status(): void
    {
        $html = Blade::render('<x-ui.status-badge status="something_new" />');

        $this->assertStringContainsString('text-status-gray', $html);
        $this->assertStringContainsString('Something new', $html);
    }

    public function test_ui_kit_is_not_found_outside_the_local_environment(): void
    {
        $this->get('/ui-kit')->assertNotFound();
    }

    public function test_ui_kit_renders_in_the_local_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->withoutVite()->get('/ui-kit')->assertOk()->assertSee('UI kit');
    }
}
