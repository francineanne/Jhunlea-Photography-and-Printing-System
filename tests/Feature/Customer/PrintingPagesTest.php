<?php

namespace Tests\Feature\Customer;

use App\Enums\PricingType;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use App\Support\PageRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MakesPdfs;
use Tests\TestCase;

/** Phase 10R: copies and pages after the files, pages counted from the PDFs by the server, and custom pages. */
class PrintingPagesTest extends TestCase
{
    use MakesPdfs, RefreshDatabase;

    private User $customer;

    private PrintingService $documents;

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
            'allowed_file_types' => ['pdf', 'jpg'],
            'max_file_size_mb' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /** A real file on disk, so the server reads its bytes the way it does for a true upload. */
    private function upload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private function send(array $payload)
    {
        return $this->actingAs($this->customer)->from(route('customer.printing.create'))->post(route('customer.printing.store'), array_replace([
            'service' => $this->documents->id,
            'quantity' => 1,
        ], $payload));
    }

    /** The wizard opens on this step: only that section is free of the hidden-before-Alpine marker. */
    private function assertStartStep(int $step, string $html): void
    {
        preg_match_all('/<section id="step-(\d)"([^>]*)>/', $html, $sections, PREG_SET_ORDER);
        $this->assertCount(4, $sections);

        foreach ($sections as [, $number, $attributes]) {
            $this->assertSame((int) $number !== $step, str_contains($attributes, 'data-js-hidden'), "Step {$number}");
        }
    }

    private function onlyOrder(): PrintingOrder
    {
        $this->assertSame(1, PrintingOrder::count());

        return PrintingOrder::firstOrFail();
    }

    public function test_copies_and_pages_are_in_step_3_after_the_file_picker(): void
    {
        $html = $this->actingAs($this->customer)->get(route('customer.printing.create'))->assertOk()->getContent();

        $step2 = substr($html, strpos($html, 'id="step-2"'), strpos($html, 'id="step-3"') - strpos($html, 'id="step-2"'));
        $step3 = substr($html, strpos($html, 'id="step-3"'), strpos($html, 'id="step-4"') - strpos($html, 'id="step-3"'));

        $this->assertStringNotContainsString('name="quantity"', $step2);
        $this->assertStringNotContainsString('name="pages"', $step2);
        $this->assertStringContainsString('Options', $step2);

        $this->assertStringContainsString('Files, copies and pages', $step3);
        $this->assertLessThan(strpos($step3, 'name="quantity"'), strpos($step3, 'id="files"'));
        $this->assertLessThan(strpos($step3, 'name="page_mode"'), strpos($step3, 'name="quantity"'));
        $this->assertLessThan(strpos($step3, 'name="notes"'), strpos($step3, 'name="page_range"'));
        $this->assertStringContainsString('All pages', $step3);
        $this->assertStringContainsString('Custom pages', $step3);
    }

    public function test_all_pages_of_one_pdf_uses_the_servers_count_not_the_number_sent(): void
    {
        $this->send(['page_mode' => 'all', 'pages' => 100, 'files' => [$this->upload('three.pdf', $this->pdf(3))]])
            ->assertRedirect()->assertSessionHasNoErrors();

        $order = $this->onlyOrder();
        $this->assertSame(3, $order->snapshot['pages']);
        $this->assertSame('15.00', $order->estimated_price);
    }

    public function test_all_pages_of_two_pdfs_is_their_sum(): void
    {
        $this->send(['files' => [$this->upload('a.pdf', $this->pdf(3)), $this->upload('b.pdf', $this->pdf(2, compressed: true))]])
            ->assertSessionHasNoErrors();

        $this->assertSame(5, $this->onlyOrder()->snapshot['pages']);
    }

    public function test_custom_pages_are_counted_and_written_at_the_top_of_the_notes(): void
    {
        $this->send(['page_mode' => 'custom', 'page_range' => ' 1-2 ', 'pages' => 99, 'notes' => 'Staple it.', 'files' => [$this->upload('three.pdf', $this->pdf(3))]])
            ->assertSessionHasNoErrors();

        $order = $this->onlyOrder();
        $this->assertSame(2, $order->snapshot['pages']);
        $this->assertStringStartsWith('Pages to print: 1-2', $order->notes);
        $this->assertSame("Pages to print: 1-2\nStaple it.", $order->notes);
    }

    public function test_custom_pages_without_notes_are_the_whole_note(): void
    {
        $this->send(['page_mode' => 'custom', 'page_range' => '3, 1-2, 2', 'files' => [$this->upload('three.pdf', $this->pdf(3))]])
            ->assertSessionHasNoErrors();

        $order = $this->onlyOrder();
        $this->assertSame(3, $order->snapshot['pages']);
        $this->assertSame('Pages to print: 3, 1-2, 2', $order->notes);
    }

    public function test_custom_pages_past_the_end_of_the_pdf_are_refused(): void
    {
        $this->send(['page_mode' => 'custom', 'page_range' => '2-9', 'files' => [$this->upload('three.pdf', $this->pdf(3))]])
            ->assertSessionHasErrors(['page_range' => 'This PDF has 3 pages. Choose pages from 1 to 3.']);

        $this->assertSame(0, PrintingOrder::count());
    }

    public function test_custom_pages_with_several_pdfs_may_count_up_to_their_total(): void
    {
        $files = fn () => [$this->upload('a.pdf', $this->pdf(3)), $this->upload('b.pdf', $this->pdf(2))];

        $this->send(['page_mode' => 'custom', 'page_range' => '1-6', 'files' => $files()])
            ->assertSessionHasErrors(['page_range' => 'Your files have 5 pages in total. Choose at most 5 pages.']);

        $this->send(['page_mode' => 'custom', 'page_range' => '1-3, 1-2', 'files' => $files()])->assertSessionHasNoErrors();
        $this->assertSame(3, $this->onlyOrder()->snapshot['pages']);
    }

    /** @return array<string, array{0: string}> */
    public static function badRanges(): array
    {
        return ['backwards' => ['5-2'], 'letters' => ['abc'], 'zero' => ['0'], 'empty part' => ['1,,2'], 'open range' => ['3-'], 'past the limit' => ['1-10001']];
    }

    #[DataProvider('badRanges')]
    public function test_bad_ranges_are_refused(string $range): void
    {
        $this->send(['page_mode' => 'custom', 'page_range' => $range, 'files' => [$this->upload('three.pdf', $this->pdf(3))]])
            ->assertSessionHasErrors(['page_range' => 'Enter the pages like 1-5, 8: page numbers from 1, commas between them, and a dash for a range such as 2-4.']);

        $this->assertSame(0, PrintingOrder::count());
        $this->assertNull(PageRange::parse($range));
    }

    public function test_an_empty_custom_range_asks_for_the_pages(): void
    {
        $this->send(['page_mode' => 'custom', 'page_range' => '', 'files' => [$this->upload('three.pdf', $this->pdf(3))]])
            ->assertSessionHasErrors(['page_range' => 'Enter the pages to print, for example 1-5, 8.']);
    }

    public function test_a_picture_uses_the_typed_pages_and_needs_them(): void
    {
        $this->send(['files' => [$this->upload('photo.jpg', $this->jpeg())]])
            ->assertSessionHasErrors(['pages' => 'Enter the number of pages in your file.']);
        $this->assertSame(0, PrintingOrder::count());

        $this->send(['pages' => 4, 'files' => [$this->upload('photo.jpg', $this->jpeg())]])->assertSessionHasNoErrors();
        $this->assertSame(4, $this->onlyOrder()->snapshot['pages']);
    }

    public function test_a_pdf_with_a_picture_uses_the_typed_pages(): void
    {
        $this->send(['pages' => 7, 'files' => [$this->upload('three.pdf', $this->pdf(3)), $this->upload('photo.jpg', $this->jpeg())]])
            ->assertSessionHasNoErrors();

        $this->assertSame(7, $this->onlyOrder()->snapshot['pages']);
    }

    public function test_an_encrypted_or_unreadable_pdf_uses_the_typed_pages(): void
    {
        $this->send(['pages' => 6, 'files' => [$this->upload('locked.pdf', $this->pdf(3, encrypted: true))]])->assertSessionHasNoErrors();
        $this->assertSame(6, $this->onlyOrder()->snapshot['pages']);

        PrintingOrder::query()->forceDelete();

        $this->send(['files' => [$this->upload('broken.pdf', "%PDF-1.4\nnothing to read here\n%%EOF\n")]])
            ->assertSessionHasErrors(['pages' => 'Enter the number of pages in your file.']);
    }

    public function test_a_service_not_priced_per_page_needs_no_pages(): void
    {
        $photos = PrintingService::factory()->published()->create([
            'pricing_type' => PricingType::PerCopy,
            'base_price' => '10.00',
            'allowed_file_types' => ['pdf', 'jpg'],
            'max_file_size_mb' => 2,
        ]);

        $this->send(['service' => $photos->id, 'quantity' => 2, 'page_mode' => 'custom', 'page_range' => 'not a range', 'files' => [$this->upload('photo.jpg', $this->jpeg())]])
            ->assertSessionHasNoErrors();

        $order = $this->onlyOrder();
        $this->assertNull($order->snapshot['pages']); // as before 10R: no pages for a service priced per copy
        $this->assertNull($order->notes);
    }

    public function test_copies_keep_their_rules(): void
    {
        $this->send(['quantity' => 0, 'files' => [$this->upload('three.pdf', $this->pdf(3))]])->assertSessionHasErrors(['quantity' => 'You need at least 1 copy.']);
        $this->send(['quantity' => config('printing.max_quantity') + 1, 'files' => [$this->upload('three.pdf', $this->pdf(3))]])->assertSessionHasErrors('quantity');
        $this->assertSame(0, PrintingOrder::count());
    }

    public function test_a_page_error_opens_step_3(): void
    {
        $this->send(['page_mode' => 'custom', 'page_range' => '9', 'files' => [$this->upload('three.pdf', $this->pdf(3))]])
            ->assertRedirect(route('customer.printing.create'));

        $html = $this->actingAs($this->customer)->get(route('customer.printing.create'))->getContent();
        $this->assertStartStep(3, $html);
    }

    public function test_the_live_estimate_counts_custom_pages_and_refuses_a_bad_range(): void
    {
        $this->actingAs($this->customer)
            ->getJson(route('customer.printing.estimate', ['service' => $this->documents->id, 'quantity' => 2, 'page_mode' => 'custom', 'page_range' => '1-5, 8']))
            ->assertOk()->assertJsonPath('total', '60.00')->assertJsonFragment(['label' => 'Pages × copies', 'value' => '6 × 2']);

        $this->actingAs($this->customer)
            ->getJson(route('customer.printing.estimate', ['service' => $this->documents->id, 'quantity' => 1, 'page_mode' => 'custom', 'page_range' => '5-2']))
            ->assertStatus(422)->assertJsonValidationErrors('page_range');

        // All pages: the browser's count (or the typed number), checked as before
        $this->actingAs($this->customer)
            ->getJson(route('customer.printing.estimate', ['service' => $this->documents->id, 'quantity' => 1, 'page_mode' => 'all', 'pages' => 3]))
            ->assertOk()->assertJsonFragment(['label' => 'Pages × copies', 'value' => '3 × 1']);
    }

    public function test_all_pages_above_the_order_limit_is_refused_on_the_files_in_step_3(): void
    {
        config(['printing.max_pages' => 4]);

        $this->send(['page_mode' => 'all', 'pages' => 2, 'files' => [$this->upload('long.pdf', $this->pdf(5))]])
            ->assertRedirect(route('customer.printing.create'))
            ->assertSessionHasErrors(['files' => 'Your files have 5 pages. Online orders can be up to 4 pages. For more, please talk to the shop.'])
            ->assertSessionDoesntHaveErrors(['options', 'pages']);

        $this->assertSame(0, PrintingOrder::count());

        // The error opens step 3, not step 2
        $html = $this->actingAs($this->customer)->from(route('customer.printing.create'))->followingRedirects()
            ->post(route('customer.printing.store'), ['service' => $this->documents->id, 'quantity' => 1, 'files' => [$this->upload('long.pdf', $this->pdf(5))]])
            ->getContent();
        $this->assertStartStep(3, $html);

        // Exactly at the limit is fine, and Custom pages within the limit are not refused for a longer PDF
        $this->send(['files' => [$this->upload('four.pdf', $this->pdf(4))]])->assertSessionHasNoErrors();
        $this->assertSame(4, $this->onlyOrder()->snapshot['pages']);
        PrintingOrder::query()->forceDelete();

        $this->send(['page_mode' => 'custom', 'page_range' => '1-3', 'files' => [$this->upload('long.pdf', $this->pdf(5))]])->assertSessionHasNoErrors();
        $this->assertSame(3, $this->onlyOrder()->snapshot['pages']);
    }

    public function test_custom_pages_leave_room_in_the_notes_for_their_line(): void
    {
        $this->send(['page_mode' => 'custom', 'page_range' => '1-2', 'notes' => str_repeat('a', 1990), 'files' => [$this->upload('three.pdf', $this->pdf(3))]])
            ->assertSessionHasErrors(['notes' => 'With custom pages, special instructions can be up to 1980 characters.']);
    }
}
