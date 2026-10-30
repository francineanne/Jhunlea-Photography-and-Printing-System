<?php

namespace Tests\Feature\Settings;

use App\Models\AvailabilityRule;
use App\Models\BookingSetting;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\ShopSettingsService;
use App\Support\ServerUploadLimits;
use App\Support\ShopSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Phase 6B: the owner's Settings page: business info, upload limits, the read-only booking and payment rules. */
class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ServerUploadLimits::class, new ServerUploadLimits(['upload_max_filesize' => '10M', 'post_max_size' => '40M', 'max_file_uploads' => '20']));
        $this->app->forgetScopedInstances();
        $this->owner = User::factory()->owner()->create();
    }

    /** @return array<string, int|string> */
    private function limits(array $overrides = []): array
    {
        return array_replace([
            'printing_max_file_mb' => 8, 'printing_max_total_mb' => 20, 'printing_max_files' => 6,
            'booking_max_image_mb' => 4, 'booking_max_images' => 3, 'booking_max_total_mb' => 10,
        ], $overrides);
    }

    private function business(array $overrides = []): array
    {
        return array_replace(['shop_name' => 'Jhunlea Studio', 'address' => "12 Rizal Street\nBatangas City", 'phone' => '+63 917 123 4567', 'email' => 'hello@example.test'], $overrides);
    }

    private function row(): ?ShopSetting
    {
        return ShopSetting::query()->find(ShopSetting::ROW_ID);
    }

    public function test_owner_only(): void
    {
        $customer = User::factory()->create();
        $routes = [
            ['get', route('owner.settings.index')],
            ['post', route('owner.settings.business')],
            ['post', route('owner.settings.uploads')],
            ['post', route('owner.settings.uploads.reset')],
        ];

        foreach ($routes as [$method, $url]) {
            $this->actingAs($customer)->{$method}($url, $method === 'post' ? $this->business() : [])->assertForbidden();
        }
        auth()->logout();
        foreach ($routes as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }
        $this->assertNull($this->row());
    }

    public function test_nothing_saved_shows_the_defaults_and_no_placeholder(): void
    {
        $html = $this->actingAs($this->owner)->get(route('owner.settings.index'))->assertOk()
            ->assertSee('Starting defaults')->assertSee('Shop settings and rules')->getContent();
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('owner.settings.index'), '/').'"\s+aria-current="page"/', $html);

        $this->get(route('home'))->assertOk()->assertDontSee('Visit or contact')->assertDontSee('mailto:', false)->assertDontSee('tel:', false);
        $this->assertNull($this->row());
    }

    public function test_business_info_is_saved_and_shown_on_the_public_site(): void
    {
        $this->actingAs($this->owner)->post(route('owner.settings.business'), $this->business())->assertRedirect(route('owner.settings.index').'#business')->assertSessionHas('status');
        $this->assertSame(['Jhunlea Studio', "12 Rizal Street\nBatangas City", '+63 917 123 4567', 'hello@example.test'], [$this->row()->shop_name, $this->row()->address, $this->row()->phone, $this->row()->email]);

        auth()->logout();
        foreach ([route('home'), route('services.index'), route('packages.index')] as $page) {
            $this->get($page)->assertOk()->assertSee('Visit or contact Jhunlea Studio')->assertSee('12 Rizal Street')->assertSee('tel:+639171234567', false)->assertSee('mailto:hello@example.test', false);
        }
    }

    public function test_an_empty_field_clears_it_and_only_set_details_show(): void
    {
        $this->actingAs($this->owner)->post(route('owner.settings.business'), $this->business());
        $this->actingAs($this->owner)->post(route('owner.settings.business'), $this->business(['address' => '   ', 'email' => '', 'shop_name' => '']))->assertSessionHasNoErrors();

        $this->assertNull($this->row()->address);
        $this->assertNull($this->row()->email);
        auth()->logout();
        $this->get(route('home'))->assertSee('Visit or contact')->assertSee('+63 917 123 4567')->assertDontSee('mailto:', false)->assertDontSee('Rizal');

        $this->actingAs($this->owner)->post(route('owner.settings.business'), ['shop_name' => '', 'address' => '', 'phone' => '', 'email' => '']);
        auth()->logout();
        $this->get(route('home'))->assertDontSee('Visit or contact');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badBusiness(): array
    {
        return [
            'long name' => [['shop_name' => str_repeat('a', 81)], 'shop_name'],
            'long address' => [['address' => str_repeat('a', 301)], 'address'],
            'control character' => [['shop_name' => "Shop\x07"], 'shop_name'],
            'letters in phone' => [['phone' => 'call me maybe'], 'phone'],
            'too few digits' => [['phone' => '12 34'], 'phone'],
            'long phone' => [['phone' => str_repeat('1', 41)], 'phone'],
            'bad email' => [['email' => 'not-an-email'], 'email'],
            'long email' => [['email' => str_repeat('a', 110).'@example.test'], 'email'],
            'array name' => [['shop_name' => ['a']], 'shop_name'],
        ];
    }

    #[DataProvider('badBusiness')]
    public function test_bad_business_info_is_refused_and_nothing_saved(array $overrides, string $field): void
    {
        $this->actingAs($this->owner)->from(route('owner.settings.index'))->post(route('owner.settings.business'), $this->business($overrides))
            ->assertRedirect(route('owner.settings.index').'#business')->assertSessionHasErrors($field);
        $this->assertNull($this->row());
    }

    public function test_typed_html_is_stored_as_text_and_shown_escaped(): void
    {
        $this->actingAs($this->owner)->post(route('owner.settings.business'), $this->business(['shop_name' => '<script>alert(1)</script>', 'address' => '<b>Bold</b> street']))->assertSessionHasNoErrors();
        $this->assertSame('<script>alert(1)</script>', $this->row()->shop_name);

        $this->actingAs($this->owner)->get(route('owner.settings.index'))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        auth()->logout();
        $this->get(route('home'))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<b>Bold</b>', false)->assertSee('&lt;b&gt;Bold&lt;/b&gt; street', false);
    }

    public function test_the_page_keeps_what_was_typed_after_an_error(): void
    {
        $this->actingAs($this->owner)->from(route('owner.settings.index'))->post(route('owner.settings.business'), $this->business(['email' => 'nope', 'shop_name' => 'Typed name']));
        $this->actingAs($this->owner)->get(route('owner.settings.index'))->assertSee('value="Typed name"', false)->assertSee('Type a valid email address');
    }

    public function test_upload_limits_are_saved_and_reset(): void
    {
        $this->actingAs($this->owner)->post(route('owner.settings.uploads'), $this->limits())->assertRedirect(route('owner.settings.index').'#uploads')->assertSessionHasNoErrors();
        $this->assertSame(8, app(ShopSettings::class)->printingMaxFileMb());
        $this->assertSame(3, $this->row()->booking_max_images);
        $this->actingAs($this->owner)->get(route('owner.settings.index'))->assertSee('Your own limits')->assertSee('Use the starting defaults');

        // The reset needs its confirm box
        $this->actingAs($this->owner)->post(route('owner.settings.uploads.reset'))->assertSessionHasErrors('confirm');
        $this->assertSame(8, $this->row()->printing_max_file_mb);

        $this->actingAs($this->owner)->post(route('owner.settings.uploads.reset'), ['confirm' => '1'])->assertSessionHasNoErrors();
        foreach (array_keys(ShopSettings::LIMITS) as $field) {
            $this->assertNull($this->row()->{$field}, $field);
        }
        $this->assertSame(10, app(ShopSettings::class)->printingMaxFileMb());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badLimits(): array
    {
        return [
            'letters' => [['printing_max_file_mb' => 'ten'], 'printing_max_file_mb'],
            'zero' => [['printing_max_files' => 0], 'printing_max_files'],
            'negative' => [['booking_max_images' => -2], 'booking_max_images'],
            'decimal' => [['booking_max_image_mb' => '2.5'], 'booking_max_image_mb'],
            'huge' => [['printing_max_total_mb' => 999999], 'printing_max_total_mb'],
            'above the server file limit' => [['printing_max_file_mb' => 11, 'printing_max_total_mb' => 30], 'printing_max_file_mb'],
            'above the server total' => [['booking_max_total_mb' => 39], 'booking_max_total_mb'],
            'more files than the server takes' => [['printing_max_files' => 21], 'printing_max_files'],
            'array' => [['printing_max_files' => [5]], 'printing_max_files'],
            'missing' => [['booking_max_images' => ''], 'booking_max_images'],
            'total below the file limit' => [['printing_max_file_mb' => 8, 'printing_max_total_mb' => 7], 'printing_max_total_mb'],
            'image total below the image limit' => [['booking_max_image_mb' => 4, 'booking_max_total_mb' => 3], 'booking_max_total_mb'],
        ];
    }

    #[DataProvider('badLimits')]
    public function test_bad_limits_are_refused_and_nothing_saved(array $overrides, string $field): void
    {
        $this->actingAs($this->owner)->post(route('owner.settings.uploads'), $this->limits($overrides))
            ->assertRedirect(route('owner.settings.index').'#uploads')->assertSessionHasErrors($field);
        $this->assertNull($this->row());
    }

    public function test_the_highest_value_the_server_takes_is_accepted(): void
    {
        $this->actingAs($this->owner)->post(route('owner.settings.uploads'), $this->limits(['printing_max_file_mb' => 10, 'printing_max_total_mb' => 38, 'printing_max_files' => 20]))->assertSessionHasNoErrors();
        $this->assertSame([10, 38, 20], [$this->row()->printing_max_file_mb, $this->row()->printing_max_total_mb, $this->row()->printing_max_files]);
    }

    public function test_a_capped_value_is_explained_on_the_page(): void
    {
        $this->actingAs($this->owner)->post(route('owner.settings.uploads'), $this->limits(['printing_max_file_mb' => 10, 'printing_max_total_mb' => 30]));
        $this->app->instance(ServerUploadLimits::class, new ServerUploadLimits(['upload_max_filesize' => '6M', 'post_max_size' => '40M', 'max_file_uploads' => '20']));
        $this->app->forgetScopedInstances();

        $this->actingAs($this->owner)->get(route('owner.settings.index'))->assertOk()
            ->assertSee('Some saved limits are above what this server accepts now')->assertSee('Capped: the saved value is above this server');
    }

    public function test_a_double_submit_changes_nothing_twice_and_sections_stay_apart(): void
    {
        $this->actingAs($this->owner)->post(route('owner.settings.business'), $this->business());
        $this->actingAs($this->owner)->post(route('owner.settings.uploads'), $this->limits());
        $first = $this->row()->getAttributes();

        $this->actingAs($this->owner)->post(route('owner.settings.uploads'), $this->limits());
        $this->assertSame(1, ShopSetting::query()->count());
        $this->assertSame(collect($first)->except('updated_at')->all(), collect($this->row()->getAttributes())->except('updated_at')->all());

        // Saving the business info never touches the limits, and the other way round
        $this->actingAs($this->owner)->post(route('owner.settings.business'), $this->business(['shop_name' => 'New name']));
        $this->assertSame(8, $this->row()->printing_max_file_mb);
        $this->actingAs($this->owner)->post(route('owner.settings.uploads.reset'), ['confirm' => '1']);
        $this->assertSame('New name', $this->row()->shop_name);
    }

    public function test_requests_cannot_set_the_id_or_timestamps(): void
    {
        $this->actingAs($this->owner)->post(route('owner.settings.business'), $this->business() + ['id' => 99, 'created_at' => '2000-01-01 00:00:00', 'printing_max_file_mb' => 1]);
        $this->actingAs($this->owner)->post(route('owner.settings.uploads'), $this->limits() + ['id' => 7, 'shop_name' => 'Sneaky', 'updated_at' => '2000-01-01']);

        $this->assertSame([ShopSetting::ROW_ID], ShopSetting::query()->pluck('id')->all());
        $this->assertSame('Jhunlea Studio', $this->row()->shop_name);
        $this->assertSame(8, $this->row()->printing_max_file_mb);
        $this->assertNotSame('2000', $this->row()->created_at->format('Y'));
        $this->assertNotContains('id', (new ShopSetting)->getFillable());

        app(ShopSettingsService::class)->saveBusiness($this->business() + ['printing_max_file_mb' => 1]);
        $this->assertSame(8, $this->row()->fresh()->printing_max_file_mb);
    }

    public function test_the_schedule_values_are_shown_read_only(): void
    {
        BookingSetting::query()->forceCreate(['id' => 1, 'buffer_minutes' => 25, 'min_notice_hours' => 48, 'max_advance_days' => 60, 'concurrent_sessions' => 2, 'lock_version' => 0]);
        $monday = new AvailabilityRule(['is_open' => true, 'opens_at' => '09:00:00', 'closes_at' => '17:30:00']);
        $monday->weekday = 1;
        $monday->save();

        $html = $this->actingAs($this->owner)->get(route('owner.settings.index'))->assertOk()
            ->assertSee('48 hours')->assertSee('60 days ahead')->assertSee('25 minutes')->assertSee('9:00 AM – 5:30 PM')
            ->assertSee('until 24 hours before it starts')->assertSee('at most 3 requests')
            ->assertSee(route('owner.schedule.edit'))->assertSee('Change in the Schedule editor')
            ->getContent();

        foreach (['buffer_minutes', 'min_notice_hours', 'max_advance_days', 'concurrent_sessions', 'hours[', 'opens_at', 'closes_at'] as $field) {
            $this->assertStringNotContainsString('name="'.$field, $html, $field);
        }
        $this->assertStringNotContainsString(route('owner.schedule.settings'), $html);
        $this->assertStringNotContainsString(route('owner.schedule.hours'), $html);
    }

    public function test_the_site_keeps_working_before_the_new_tables_exist(): void
    {
        Schema::drop('faqs');
        Schema::drop('shop_settings');
        $this->app->forgetScopedInstances();

        $this->get(route('home'))->assertOk()->assertDontSee('Visit or contact');
        $this->assertSame(10, app(ShopSettings::class)->printingMaxFileMb());
    }

    public function test_the_payment_rules_are_fixed_text_without_a_switch(): void
    {
        $html = $this->actingAs($this->owner)->get(route('owner.settings.index'))->assertOk()
            ->assertSee('Payment is made face to face at the studio (cash or GCash). The system only records payments. Nothing is charged online.')
            ->assertSee('Full payment is required before an order or the photos are released.')
            ->getContent();

        $payments = substr($html, strpos($html, 'id="payments"'), 2500);
        $this->assertStringNotContainsString('<input', $payments);
        $this->assertStringNotContainsString('role="switch"', $payments);
        $this->assertStringNotContainsStringIgnoringCase('partial', $html);
        $this->assertStringNotContainsStringIgnoringCase('deposit', $html);
    }
}
