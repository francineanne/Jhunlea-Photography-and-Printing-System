<?php

namespace Tests\Feature\Customer;

use App\Enums\OptionGroup;
use App\Enums\PricingType;
use App\Enums\PrintingOrderStatus;
use App\Models\OrderFile;
use App\Models\OrderStatusHistory;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\PrintingServiceOption;
use App\Models\User;
use App\Services\OrderFileService;
use App\Services\PrintingOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PrintingWizardTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private User $customer;

    private PrintingService $documents;

    private PrintingServiceOption $a4;

    private PrintingServiceOption $long;

    private PrintingServiceOption $mono;

    private PrintingServiceOption $color;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->customer = User::factory()->create();
        $this->documents = PrintingService::factory()->published()->create([
            'name' => 'Document Printing',
            'pricing_type' => PricingType::PerPage,
            'base_price' => '5.00',
            'allowed_file_types' => ['pdf', 'jpg', 'png'],
            'max_file_size_mb' => 2,
        ]);
        $this->a4 = $this->option($this->documents, OptionGroup::PaperSize, 'A4', '0.00');
        $this->long = $this->option($this->documents, OptionGroup::PaperSize, 'Long', '1.00');
        $this->mono = $this->option($this->documents, OptionGroup::ColorMode, 'Black and white', '0.00');
        $this->color = $this->option($this->documents, OptionGroup::ColorMode, 'Full color', '2.00');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function option(PrintingService $service, OptionGroup $group, string $label, string $modifier): PrintingServiceOption
    {
        return PrintingServiceOption::factory()->create([
            'printing_service_id' => $service->id,
            'group' => $group,
            'label' => $label,
            'price_modifier' => $modifier,
        ]);
    }

    /** A real file on disk, so the server reads its bytes the way it does for a true upload. */
    private function upload(string $name, string $contents = self::PDF): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'service' => $this->documents->id,
            'options' => [$this->documents->id => ['paper_size' => $this->long->id, 'color_mode' => $this->color->id]],
            'quantity' => 2,
            'pages' => 3,
            'notes' => 'Staple each copy.',
            'files' => [$this->upload('thesis.pdf')],
        ], $overrides);
    }

    private function send(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->customer)->from(route('customer.printing.create'))->post(route('customer.printing.store'), $payload);
    }

    private function assertNothingSaved(): void
    {
        $this->assertSame(0, PrintingOrder::withTrashed()->count());
        $this->assertSame(0, OrderFile::count());
        $this->assertSame(0, OrderStatusHistory::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // Access

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('customer.printing.create'))->assertRedirect('/login');
        $this->post(route('customer.printing.store'))->assertRedirect('/login');
    }

    public function test_the_owner_cannot_use_the_wizard(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get(route('customer.printing.create'))->assertForbidden();
        $this->actingAs($owner)->post(route('customer.printing.store'), $this->payload())->assertForbidden();
        $this->actingAs($owner)->getJson(route('customer.printing.estimate', ['service' => $this->documents->id, 'quantity' => 1, 'pages' => 1]))->assertForbidden();
        $this->assertNothingSaved();
    }

    // The page

    public function test_the_wizard_lists_only_published_services(): void
    {
        PrintingService::factory()->create(['name' => 'Hidden Draft Service']);
        PrintingService::factory()->published()->create(['name' => 'Removed Service'])->delete();

        $this->actingAs($this->customer)->get(route('customer.printing.create'))
            ->assertOk()
            ->assertSee('Document Printing')
            ->assertDontSee('Hidden Draft Service')
            ->assertDontSee('Removed Service');
    }

    public function test_every_step_is_in_the_server_html_so_the_form_works_without_scripts(): void
    {
        $html = $this->actingAs($this->customer)->get(route('customer.printing.create'))->assertOk()->getContent();

        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        foreach (['step-1', 'step-2', 'step-3', 'step-4'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
        $this->assertStringContainsString('name="service" value="'.$this->documents->id.'"', $html);
        $this->assertStringContainsString('name="options['.$this->documents->id.'][paper_size]" value="'.$this->a4->id.'"', $html);
        $this->assertStringContainsString('name="quantity"', $html);
        $this->assertStringContainsString('name="pages"', $html);
        $this->assertStringContainsString('name="files[]" multiple', $html);
        $this->assertStringContainsString('name="notes"', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('Estimate only. The shop confirms the final price after checking your file.', $html);
        $this->assertStringContainsString('Payment is made face-to-face at Jhunlea Photography &amp; Printing.', $html);
        // Step 1 is open; only the other steps carry the marker that hides them before Alpine starts
        // (steps 2, 3 and 4, plus the pages field while no per-page service is chosen)
        $this->assertSame(4, substr_count($html, 'data-js-hidden x-bind:data-js-hidden="false"'));
        $this->assertDoesNotMatchRegularExpression('/id="step-1"[^>]*data-js-hidden/', $html);
        $this->assertMatchesRegularExpression('/id="step-2"[^>]*data-js-hidden/', $html);
    }

    public function test_the_first_option_of_each_group_is_ticked_so_a_plain_form_is_complete(): void
    {
        $html = $this->actingAs($this->customer)->get(route('customer.printing.create'))->getContent();

        $this->assertMatchesRegularExpression('/value="'.$this->a4->id.'"\s+data-label="Paper size: A4"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="'.$this->long->id.'"\s+data-label="Paper size: Long"\s+checked/', $html);
    }

    public function test_a_shop_with_no_published_services_shows_an_empty_state(): void
    {
        $this->documents->update(['is_published' => false]);

        $this->actingAs($this->customer)->get(route('customer.printing.create'))
            ->assertOk()
            ->assertSee('No printing services are open for requests yet')
            ->assertDontSee('name="files[]"', false);
    }

    // Placing an order

    public function test_a_customer_places_an_order_with_files_and_sees_the_confirmation(): void
    {
        $response = $this->send($this->payload(['files' => [$this->upload('thesis.pdf'), $this->upload('cover.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='))]]));

        $order = PrintingOrder::sole();
        $response->assertRedirect(route('customer.printing.placed', $order));

        $this->assertSame('PR-000001', $order->reference_number);
        $this->assertSame($this->customer->id, $order->customer_id);
        $this->assertSame(PrintingOrderStatus::Submitted, $order->status);
        $this->assertSame(2, $order->quantity);
        $this->assertSame('Staple each copy.', $order->notes);
        // (5.00 + 1.00 + 2.00) x 3 pages x 2 copies
        $this->assertSame('48.00', $order->estimated_price);
        $this->assertNull($order->confirmed_price);
        $this->assertSame(3, $order->snapshot['pages']);
        $this->assertCount(2, $order->files);
        $this->assertSame(['thesis.pdf', 'cover.png'], $order->files()->orderBy('id')->pluck('original_name')->all());
        foreach ($order->files as $file) {
            Storage::disk('local')->assertExists($file->path);
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(1, $order->statusHistories()->count());

        $this->get(route('customer.printing.placed', $order))
            ->assertOk()
            ->assertSee('PR-000001')
            ->assertSee('Document Printing')
            ->assertSee('₱48.00')
            ->assertSee('Paper size: Long')
            ->assertSee('Color mode: Full color')
            ->assertSee('thesis.pdf')
            ->assertSee('What happens next')
            ->assertSee('Payment is made face-to-face at Jhunlea Photography &amp; Printing.', false)
            ->assertDontSee('Track this order');
    }

    public function test_a_quotation_service_shows_price_quoted_after_review_and_no_figure(): void
    {
        $tarp = PrintingService::factory()->published()->quotation()->create(['name' => 'Tarpaulin', 'allowed_file_types' => ['pdf'], 'max_file_size_mb' => 2]);

        $this->send(['service' => $tarp->id, 'quantity' => 1, 'files' => [$this->upload('banner.pdf')]])->assertRedirect();

        $order = PrintingOrder::sole();
        $this->assertNull($order->estimated_price);

        $this->get(route('customer.printing.placed', $order))
            ->assertOk()
            ->assertSee('Price quoted after review')
            ->assertDontSee('Estimate only. The shop confirms');
    }

    public function test_pages_are_ignored_for_a_service_priced_per_copy(): void
    {
        $photos = PrintingService::factory()->published()->create(['pricing_type' => PricingType::PerCopy, 'base_price' => '15.00', 'allowed_file_types' => ['pdf'], 'max_file_size_mb' => 2]);

        $this->send(['service' => $photos->id, 'quantity' => 4, 'files' => [$this->upload('a.pdf')]])->assertRedirect();

        $order = PrintingOrder::sole();
        $this->assertSame('60.00', $order->estimated_price);
        $this->assertNull($order->snapshot['pages']);
    }

    public function test_choices_left_under_another_service_are_ignored(): void
    {
        $other = PrintingService::factory()->published()->create(['allowed_file_types' => ['pdf']]);
        $otherOption = $this->option($other, OptionGroup::Finish, 'Glossy', '50.00');

        $payload = $this->payload();
        $payload['options'][$other->id] = ['finish' => $otherOption->id];

        $this->send($payload)->assertRedirect();

        $this->assertSame('48.00', PrintingOrder::sole()->estimated_price);
        $this->assertNotContains('Glossy', array_column(PrintingOrder::sole()->snapshot['options'], 'label'));
    }

    // Validation: every rule refuses and saves nothing

    /** @return array<string, array{\Closure, string}> */
    public static function badInput(): array
    {
        return [
            'no service' => [fn ($t) => ['service' => null], 'service'],
            'service that does not exist' => [fn ($t) => ['service' => 999999], 'service'],
            'service id that is not a number' => [fn ($t) => ['service' => 'abc'], 'service'],
            'no copies' => [fn ($t) => ['quantity' => null], 'quantity'],
            'zero copies' => [fn ($t) => ['quantity' => 0], 'quantity'],
            'negative copies' => [fn ($t) => ['quantity' => -5], 'quantity'],
            'huge quantity' => [fn ($t) => ['quantity' => 10001], 'quantity'],
            'copies with decimals' => [fn ($t) => ['quantity' => '1.5'], 'quantity'],
            'no pages on a per page service' => [fn ($t) => ['pages' => null], 'pages'],
            'zero pages' => [fn ($t) => ['pages' => 0], 'pages'],
            'huge number of pages' => [fn ($t) => ['pages' => 10001], 'pages'],
            'no files' => [fn ($t) => ['files' => []], 'files'],
            'too many files' => [fn ($t) => ['files' => array_map(fn ($i) => $t->upload("f{$i}.pdf"), range(1, 11))], 'files'],
            'wrong mime: a program named pdf' => [fn ($t) => ['files' => [$t->upload('invoice.pdf', '<?php echo shell_exec($_GET["c"]);')]], 'files.0'],
            'empty file' => [fn ($t) => ['files' => [$t->upload('empty.pdf', '')]], 'files.0'],
            'extension the service does not accept' => [fn ($t) => ['files' => [$t->upload('letter.docx')]], 'files.0'],
            'extension does not match the bytes' => [fn ($t) => ['files' => [$t->upload('photo.png', self::PDF)]], 'files.0'],
            'file over the service limit' => [fn ($t) => ['files' => [$t->upload('big.pdf', self::PDF.str_repeat('0', 2 * 1024 * 1024))]], 'files.0'],
            'a file field that is not a file' => [fn ($t) => ['files' => ['not a file']], 'files.0'],
            'notes too long' => [fn ($t) => ['notes' => str_repeat('a', 2001)], 'notes'],
            'option id that is not a number' => [fn ($t) => ['options' => [$t->documents->id => ['paper_size' => 'abc', 'color_mode' => $t->mono->id]]], 'options.'.'*'],
        ];
    }

    #[DataProvider('badInput')]
    public function test_bad_input_is_refused_and_nothing_is_saved(\Closure $change, string $field): void
    {
        $response = $this->send($this->payload($change($this)));

        $response->assertRedirect(route('customer.printing.create'));
        if (str_ends_with($field, '*')) {
            $response->assertSessionHasErrors("options.{$this->documents->id}.paper_size");
        } else {
            $response->assertSessionHasErrors($field);
        }
        $this->assertNothingSaved();
    }

    public function test_an_unpublished_or_removed_service_cannot_be_ordered(): void
    {
        $draft = PrintingService::factory()->create(['allowed_file_types' => ['pdf']]);
        $removed = PrintingService::factory()->published()->create(['allowed_file_types' => ['pdf']]);
        $removed->delete();

        foreach ([$draft, $removed] as $service) {
            $this->send(['service' => $service->id, 'quantity' => 1, 'files' => [$this->upload('a.pdf')]])->assertSessionHasErrors('service');
        }

        $this->assertNothingSaved();
    }

    public function test_an_option_of_another_service_is_refused(): void
    {
        $other = PrintingService::factory()->published()->create();
        $foreign = $this->option($other, OptionGroup::PaperSize, 'A3', '0.00');

        $this->send($this->payload(['options' => [$this->documents->id => ['paper_size' => $foreign->id, 'color_mode' => $this->mono->id]]]))
            ->assertSessionHasErrors('options');

        $this->assertNothingSaved();
    }

    public function test_two_options_from_one_group_are_refused(): void
    {
        // Both are paper sizes; one is sent under the color mode name to slip it past the form
        $this->send($this->payload(['options' => [$this->documents->id => ['paper_size' => $this->a4->id, 'color_mode' => $this->long->id]]]))
            ->assertSessionHasErrors('options');

        $this->assertNothingSaved();
    }

    public function test_a_switched_off_option_is_refused(): void
    {
        $this->long->update(['is_active' => false]);

        $this->send($this->payload())->assertSessionHasErrors('options');

        $this->assertNothingSaved();
    }

    public function test_every_option_group_of_the_service_needs_a_choice(): void
    {
        $this->send($this->payload(['options' => [$this->documents->id => ['paper_size' => $this->a4->id]]]))
            ->assertSessionHasErrors('options.color_mode');

        $this->assertNothingSaved();
    }

    public function test_an_order_too_large_to_estimate_is_refused(): void
    {
        $this->documents->update(['base_price' => '99999.00']);

        $this->send($this->payload(['quantity' => 10000, 'pages' => 10000]))->assertSessionHasErrors('options');

        $this->assertNothingSaved();
    }

    public function test_a_failed_submit_opens_the_step_with_the_first_problem_and_keeps_the_typed_values(): void
    {
        $this->send($this->payload(['files' => [], 'notes' => 'Keep me']));

        $html = $this->actingAs($this->customer)->get(route('customer.printing.create'))->getContent();

        // Step 3 opens: it is the one section without the marker that hides it before Alpine starts
        $this->assertDoesNotMatchRegularExpression('/id="step-3"[^>]*data-js-hidden/', $html);
        $this->assertMatchesRegularExpression('/id="step-1"[^>]*data-js-hidden/', $html);
        $this->assertStringContainsString('Keep me', $html);
        $this->assertStringContainsString('Add at least one file to print.', $html);
        $this->assertStringContainsString('Files are not kept when something needs fixing.', $html);
        $this->assertMatchesRegularExpression('/value="'.$this->long->id.'"\s+data-label="Paper size: Long"\s+checked/', $html);
    }

    // Atomicity

    public function test_a_failure_after_the_first_file_is_written_leaves_no_order_and_no_file(): void
    {
        $this->app->instance(OrderFileService::class, new class extends OrderFileService
        {
            public int $calls = 0;

            public function store(PrintingOrder $order, UploadedFile $file, User $uploader): OrderFile
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('The disk is full.');
                }

                return parent::store($order, $file, $uploader);
            }
        });

        $this->send($this->payload(['files' => [$this->upload('one.pdf'), $this->upload('two.pdf')]]))
            ->assertRedirect(route('customer.printing.create'))
            ->assertSessionHas('error');

        $this->assertNothingSaved();
        // Not even the empty folder of the rolled-back order is left
        $this->assertSame([], array_values(array_filter(Storage::disk('local')->allDirectories(), fn ($dir) => str_contains($dir, '/'))));

        // The reference number was given back with the rest
        $this->app->forgetInstance(OrderFileService::class);
        $this->app->forgetInstance(PrintingOrderService::class);
        $this->send($this->payload())->assertRedirect();
        $this->assertSame('PR-000001', PrintingOrder::sole()->reference_number);
    }

    public function test_every_file_is_checked_before_anything_is_saved(): void
    {
        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_starts_with(strtolower($query->sql), 'insert')) {
                $queries++;
            }
        });

        // The second file is bad: the first must not be stored while the order waits
        $this->send($this->payload(['files' => [$this->upload('good.pdf'), $this->upload('bad.pdf', 'not a pdf')]]))
            ->assertSessionHasErrors('files.1');

        $this->assertSame(0, $queries);
        $this->assertNothingSaved();
    }

    // The confirmation page

    public function test_another_customer_cannot_see_the_confirmation_page(): void
    {
        $this->send($this->payload());
        $order = PrintingOrder::sole();

        $this->actingAs(User::factory()->create())->get(route('customer.printing.placed', $order))->assertForbidden();
        $this->actingAs(User::factory()->owner()->create())->get(route('customer.printing.placed', $order))->assertForbidden();
    }

    public function test_an_unknown_order_is_not_found_and_a_guest_is_sent_to_login(): void
    {
        $this->actingAs($this->customer)->get('/my/printing/999999/placed')->assertNotFound();
        auth()->logout();
        $this->get('/my/printing/1/placed')->assertRedirect('/login');
    }

    public function test_the_confirmation_page_shows_the_time_in_the_shops_time_zone(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 28)->setTime(2, 8));
        $this->send($this->payload());

        // Stored in UTC, shown in Manila time (UTC+8)
        $this->get(route('customer.printing.placed', PrintingOrder::sole()))->assertSee('Oct 28, 2026 10:08 AM');
    }

    public function test_the_confirmation_page_never_links_to_a_stored_file(): void
    {
        $this->send($this->payload());
        $order = PrintingOrder::sole();
        $file = $order->files->first();

        $this->get(route('customer.printing.placed', $order))
            ->assertOk()
            ->assertDontSee($file->stored_name)
            ->assertDontSee('/storage/', false);
    }

    // The live estimate

    public function test_the_estimate_is_the_same_result_the_order_is_saved_with(): void
    {
        $response = $this->actingAs($this->customer)->getJson(route('customer.printing.estimate', [
            'service' => $this->documents->id,
            'options' => [$this->documents->id => ['paper_size' => $this->long->id, 'color_mode' => $this->color->id]],
            'quantity' => 2,
            'pages' => 3,
        ]));

        $response->assertOk()->assertJson([
            'quoted' => false,
            'total' => '48.00',
            'total_text' => '₱48.00',
        ]);
        $this->assertSame([
            ['label' => 'Base price per page', 'value' => '₱5.00'],
            ['label' => 'Paper size: Long', 'value' => '+₱1.00'],
            ['label' => 'Color mode: Full color', 'value' => '+₱2.00'],
            ['label' => 'Price per page', 'value' => '₱8.00'],
            ['label' => 'Pages × copies', 'value' => '3 × 2'],
        ], $response->json('lines'));

        $this->send($this->payload());
        $this->assertSame($response->json('total'), PrintingOrder::sole()->estimated_price);
    }

    public function test_the_estimate_of_a_quotation_service_has_no_figure(): void
    {
        $tarp = PrintingService::factory()->published()->quotation()->create();

        $this->actingAs($this->customer)->getJson(route('customer.printing.estimate', ['service' => $tarp->id, 'quantity' => 3]))
            ->assertOk()
            ->assertExactJson(['quoted' => true, 'total' => null, 'total_text' => 'Price quoted after review', 'lines' => []]);
    }

    public function test_the_estimate_refuses_bad_input_with_a_message(): void
    {
        $other = PrintingService::factory()->published()->create();
        $foreign = $this->option($other, OptionGroup::PaperSize, 'A3', '0.00');

        $this->actingAs($this->customer)->getJson(route('customer.printing.estimate', ['service' => $this->documents->id, 'quantity' => 0, 'pages' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->actingAs($this->customer)->getJson(route('customer.printing.estimate', [
            'service' => $this->documents->id,
            'options' => [$this->documents->id => ['paper_size' => $foreign->id, 'color_mode' => $this->mono->id]],
            'quantity' => 1,
            'pages' => 1,
        ]))->assertUnprocessable()->assertJsonValidationErrors('options');

        $this->actingAs($this->customer)->getJson(route('customer.printing.estimate', ['service' => 999999, 'quantity' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('service');
    }

    // Throttle

    public function test_sending_orders_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->send([])->assertRedirect();
        }

        $this->send([])->assertTooManyRequests();
    }

    public function test_the_estimate_is_rate_limited(): void
    {
        $url = route('customer.printing.estimate', ['service' => $this->documents->id, 'quantity' => 1, 'pages' => 1]);

        for ($i = 0; $i < 120; $i++) {
            $this->actingAs($this->customer)->getJson($url);
        }

        $this->actingAs($this->customer)->getJson($url)->assertTooManyRequests();
    }

    public function test_customer_navigation_links_to_the_wizard(): void
    {
        $this->actingAs($this->customer)->get(route('customer.dashboard'))
            ->assertSee(route('customer.printing.create'), false)
            ->assertSee('Request printing');
    }
}
