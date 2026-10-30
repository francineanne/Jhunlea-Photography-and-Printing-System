<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantAuthor;
use App\Enums\AssistantFeature;
use App\Enums\PaymentMethod;
use App\Models\BookingReferenceImage;
use App\Models\Faq;
use App\Models\OrderFile;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\PrintingServiceOption;
use App\Models\Theme;
use App\Models\User;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\Providers\ProviderMessage;
use App\Services\Assistant\ThreadMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/** Nothing private ever reaches the model: only the published catalog, published FAQs and the shop's own info. */
class PromptPrivacyTest extends TestCase
{
    use FakesAssistant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
    }

    public function test_the_prompt_holds_the_published_catalog_and_none_of_the_private_records(): void
    {
        $service = PrintingService::factory()->published()->create(['name' => 'Glossy Photo Print', 'description' => 'Bright glossy prints.']);
        PrintingServiceOption::factory()->for($service, 'service')->create(['label' => 'A4 Glossy']);
        $package = PhotographyPackage::factory()->published()->create(['name' => 'Wedding Classic', 'inclusions' => ['Two photographers']]);
        $theme = Theme::factory()->published()->create(['name' => 'Rustic Garden']);
        Faq::factory()->create(['question' => 'Do you print on Sundays?', 'answer' => 'The shop is closed on Sundays.']);

        $secretService = PrintingService::factory()->create(['name' => 'Secret Draft Service']);
        PhotographyPackage::factory()->create(['name' => 'Unreleased Package']);
        Theme::factory()->create(['name' => 'Hidden Theme']);
        Faq::factory()->hidden()->create(['question' => 'Internal unpublished question?']);

        $customer = User::factory()->create(['name' => 'Rosario Dimaculangan', 'email' => 'rosario.private@example.test', 'phone' => '09998887777']);
        $order = PrintingOrder::factory()->forCustomer($customer)->create([
            'reference_number' => 'PR-424242', 'notes' => 'Deliver to Barangay Secret Street', 'rejection_reason' => 'Blurry scan from Rosario',
            'printing_service_id' => $secretService->id,
        ]);
        OrderFile::factory()->create(['printing_order_id' => $order->id, 'original_name' => 'rosario-passport.pdf', 'path' => 'printing-orders/99/hidden-path-xyz.pdf']);
        $booking = PhotographyBooking::factory()->forCustomer($customer)->create([
            'reference_number' => 'PB-535353', 'contact_number' => '09175550000', 'notes' => 'Surprise proposal for Liza', 'internal_notes' => 'VIP owner note',
            'photography_package_id' => $package->id,
        ]);
        BookingReferenceImage::factory()->create(['photography_booking_id' => $booking->id, 'original_name' => 'liza-face.jpg', 'path' => 'booking-images/1/ref-secret.jpg']);
        Payment::factory()->paying($order)->create(['reference_number' => 'PAY-777111', 'method' => PaymentMethod::Gcash, 'gcash_reference' => 'GCASHSECRET99']);
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'order_accepted', 'notifiable_type' => 'user', 'notifiable_id' => $customer->id,
            'data' => json_encode(['reference' => 'PR-NOTIFY-1']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->fake->push(self::reply('answer', 'Here are some options.'));
        $this->ask('Magkano ang wedding at glossy print? Rustic theme ba meron?', actor: AssistantActor::user($customer->id));

        $prompt = $this->fake->lastRequest()->promptText();

        foreach (['Glossy Photo Print', 'A4 Glossy', 'Wedding Classic', 'Two photographers', 'Rustic Garden', 'Do you print on Sundays?', 'closed on Sundays',
            "service #{$service->id}", "package #{$package->id}", "theme #{$theme->id}"] as $public) {
            $this->assertStringContainsString($public, $prompt);
        }

        foreach (['Secret Draft Service', 'Unreleased Package', 'Hidden Theme', 'Internal unpublished question', 'Rosario', 'Dimaculangan',
            'rosario.private@example.test', '09998887777', 'PR-424242', 'Barangay Secret Street', 'Blurry scan', 'rosario-passport.pdf',
            'hidden-path-xyz', 'PB-535353', '09175550000', 'Surprise proposal', 'VIP owner note', 'liza-face.jpg', 'ref-secret.jpg',
            'PAY-777111', 'GCASHSECRET99', 'PR-NOTIFY-1'] as $private) {
            $this->assertStringNotContainsString($private, $prompt, "{$private} reached the prompt");
        }
    }

    public function test_customer_text_is_redacted_and_only_ever_in_the_user_role(): void
    {
        $this->ask('Ako si Ana, 0917 123 4567, ana@example.test. Magkano ang print?', history: [
            new ThreadMessage(AssistantAuthor::Customer, 'My old number is +63 918 222 3333'),
            new ThreadMessage(AssistantAuthor::Lumi, 'Hello! How can I help?'),
        ]);

        $request = $this->fake->lastRequest();

        $this->assertStringNotContainsString('0917 123 4567', $request->promptText());
        $this->assertStringNotContainsString('ana@example.test', $request->promptText());
        $this->assertStringNotContainsString('918 222 3333', $request->promptText());
        $this->assertStringNotContainsString('Magkano ang print', $request->systemInstruction);
        $this->assertStringNotContainsString('My old number', $request->systemInstruction);

        $last = $request->messages[array_key_last($request->messages)];
        $this->assertSame(ProviderMessage::USER, $last->role);
        $this->assertStringContainsString('[phone]', $last->text);
        $this->assertStringContainsString('[email]', $last->text);
        $this->assertSame(ProviderMessage::USER, $request->messages[0]->role);
    }

    public function test_owner_assist_sends_only_the_given_thread_redacted_in_the_user_role(): void
    {
        $other = User::factory()->create(['name' => 'Other Customer Zeta']);
        PrintingOrder::factory()->forCustomer($other)->create(['notes' => 'Other thread secret']);

        $this->ask('Can I pay with GCash? My email is juan@example.test', AssistantFeature::OwnerAssistSummarize, AssistantActor::user(1), [
            new ThreadMessage(AssistantAuthor::Customer, 'Hi, I want 20 photo prints.'),
            new ThreadMessage(AssistantAuthor::Owner, 'Sure, please upload the files.'),
        ]);

        $request = $this->fake->lastRequest();

        $this->assertCount(1, $request->messages);
        $this->assertSame(ProviderMessage::USER, $request->messages[0]->role);
        $this->assertStringContainsString('Customer: Hi, I want 20 photo prints.', $request->messages[0]->text);
        $this->assertStringContainsString('Studio: Sure, please upload the files.', $request->messages[0]->text);
        $this->assertStringContainsString('[email]', $request->messages[0]->text);
        $this->assertStringNotContainsString('juan@example.test', $request->promptText());
        $this->assertStringNotContainsString('Other Customer Zeta', $request->promptText());
        $this->assertStringNotContainsString('Other thread secret', $request->promptText());
        $this->assertStringContainsString('summary', $request->systemInstruction);
    }

    public function test_the_knowledge_stays_under_the_context_limit_and_ranks_the_matching_item_first(): void
    {
        config(['assistant.context_max_chars' => 2500]);

        PrintingService::factory()->published()->count(30)->create(['description' => str_repeat('Plain document printing. ', 10)]);
        $match = PhotographyPackage::factory()->published()->create(['name' => 'Maternity Glow', 'short_description' => 'Soft maternity portraits.']);

        $this->ask('May maternity shoot ba kayo?');

        $system = $this->fake->lastRequest()->systemInstruction;
        $this->assertStringContainsString("[package #{$match->id}] Maternity Glow", $system);
        $this->assertStringContainsString('PAYMENT RULES', $system);
        $this->assertStringContainsString('No partial payment and no downpayment', $system);
        $this->assertStringContainsString('Sunday: closed', $system);
        $this->assertLessThan(2500 + 6000, mb_strlen($system), 'The rules plus at most context_max_chars of knowledge');
    }
}
