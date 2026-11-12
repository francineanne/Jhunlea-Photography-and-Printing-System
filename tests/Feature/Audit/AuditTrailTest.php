<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PrintingOrderStatus as Status;
use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\PaymentException;
use App\Http\Controllers\Owner\ActivityLogController;
use App\Models\AuditLog;
use App\Models\Faq;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use App\Services\Assistant\Redactor;
use App\Services\Audit\AuditChain;
use App\Services\Audit\AuditLogger;
use App\Services\BookingTransitionService;
use App\Services\FaqService;
use App\Services\PaymentService;
use App\Services\ScheduleEditService;
use App\Services\ShopSettingsService;
use App\Services\StatusTransitionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/** Phase 8E: the audit trail (append-only, one row per action in the action's transaction, a verifiable hash chain). */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->owner = User::factory()->owner()->create(['name' => 'Jhun Owner', 'email' => 'owner@example.test', 'password' => 'Owner-Pass-1234']);
        $this->customer = User::factory()->create(['name' => 'Ana Reyes', 'phone' => '09171234567']);
        $this->actingAs($this->owner);
    }

    private function order(Status $status = Status::Accepted, string $price = '500.00'): PrintingOrder
    {
        return PrintingOrder::factory()->status($status)->forCustomer($this->customer)->create(['confirmed_price' => $price]);
    }

    /** @return list<string> */
    private function actions(): array
    {
        return AuditLog::query()->orderBy('id')->get()->map(fn (AuditLog $row) => $row->action->value)->all();
    }

    public function test_payments_write_one_row_each_with_whitelisted_meta_and_never_the_gcash_reference(): void
    {
        $order = $this->order();
        $payments = app(PaymentService::class);

        $payment = $payments->record($order, $this->owner, PaymentMethod::Gcash, 'GC-SECRET-778899');
        $row = AuditLog::query()->sole();
        $this->assertSame(AuditAction::PaymentRecorded, $row->action);
        $this->assertSame(['payment', $payment->id, $payment->reference_number], [$row->subject_type, $row->subject_id, $row->subject_label]);
        $this->assertSame(['amount_cents' => 50000, 'for' => $order->reference_number, 'method' => 'gcash'], collect($row->meta)->sortKeys()->all());
        $this->assertSame(['Jhun Owner', 'owner', $this->owner->id], [$row->actor_name, $row->actor_role, $row->actor_id]);
        $this->assertStringContainsString("Recorded {$payment->reference_number} (₱500.00, GCash) for {$order->reference_number}.", $row->summary);

        // A double submit is refused by PaymentService and writes nothing more
        try {
            $payments->record($order, $this->owner, PaymentMethod::Cash);
            $this->fail('A second payment must be refused');
        } catch (PaymentException) {
        }
        $this->assertSame(1, AuditLog::query()->count());

        $payments->void($order, $payment, $this->owner, 'Wrong order. Call 0917 765 4321 to fix');
        $void = AuditLog::query()->latest('id')->first();
        $this->assertSame(AuditAction::PaymentVoided, $void->action);
        $this->assertStringContainsString('Reason: Wrong order. Call [phone] to fix.', $void->summary);

        $this->assertStringNotContainsString('GC-SECRET', json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_a_rolled_back_or_refused_action_writes_no_row(): void
    {
        $order = $this->order();

        try {
            DB::transaction(function () use ($order) {
                app(PaymentService::class)->record($order, $this->owner, PaymentMethod::Cash);
                throw new RuntimeException('Something after the payment failed');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(0, AuditLog::query()->count());

        // Rejecting needs a reason: refused, so nothing is logged
        $submitted = $this->order(Status::UnderReview, '0.00');
        try {
            app(StatusTransitionService::class)->transition($submitted, Status::Rejected, $this->owner);
        } catch (InvalidStatusTransitionException) {
        }
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_orders_and_bookings_log_status_price_cancel_and_release(): void
    {
        $transitions = app(StatusTransitionService::class);
        $order = $this->order(Status::UnderReview, '0.00');

        $transitions->transition($order, Status::Accepted, $this->owner, null, '250.00');
        $transitions->changePrice($order, $this->owner, '300.00', 'Extra pages');
        $transitions->changePrice($order, $this->owner, '300.00'); // the same price: nothing changes, nothing is logged
        $transitions->transition($order, Status::Cancelled, $this->owner, 'Customer asked');

        $this->assertSame(['order.status_changed', 'order.price_changed', 'order.cancelled'], $this->actions());
        $rows = AuditLog::query()->orderBy('id')->get();
        $this->assertSame(['from' => 'under_review', 'price_cents' => 25000, 'to' => 'accepted'], collect($rows[0]->meta)->sortKeys()->all());
        $this->assertSame(['new_cents' => 30000, 'old_cents' => 25000, 'reason' => 'Extra pages'], collect($rows[1]->meta)->sortKeys()->all());
        $this->assertSame('Customer asked', $rows[2]->meta['reason']);

        $processing = $this->order(Status::Accepted);
        $transitions->transition($processing, Status::Processing, $this->owner);
        $this->assertSame('order.status_changed', AuditLog::query()->latest('id')->first()->action->value);

        $booking = PhotographyBooking::factory()->status(BookingStatus::Confirmed)->forCustomer($this->customer)->at(Carbon::now('UTC')->subHour())->create(['confirmed_price' => '1500.00']);
        app(PaymentService::class)->record($booking, $this->owner, PaymentMethod::Cash);
        app(BookingTransitionService::class)->complete($booking, $this->owner);
        $this->assertSame(['payment.recorded', 'booking.completed'], array_slice($this->actions(), -2));
    }

    public function test_the_walk_in_counter_flow_logs_each_step_in_order_without_the_phone(): void
    {
        $service = PrintingService::factory()->published()->create(['name' => 'Document Printing', 'pricing_type' => 'per_page', 'base_price' => '5.00', 'allowed_file_types' => ['pdf']]);

        $this->post(route('owner.orders.walk-in.store'), [
            'counter_token' => (string) Str::uuid(), 'new_customer_name' => 'Lola Walk-in', 'new_customer_phone' => '0917 555 0101', 'service' => $service->id,
            'quantity' => 2, 'pages' => 3, 'price_choice' => 'catalog', 'method' => 'cash', 'collected' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(['customer.walk_in_created', 'order.walk_in_created', 'payment.recorded', 'order.status_changed'], $this->actions());
        $this->assertSame('Lola Walk-in', AuditLog::query()->where('action', 'customer.walk_in_created')->value('subject_label'));

        $all = json_encode(AuditLog::query()->get(['summary', 'meta', 'subject_label'])->toArray());
        $this->assertDoesNotMatchRegularExpression('/0917|555\s?0101|@/', $all);
    }

    public function test_schedule_settings_faq_and_catalog_changes(): void
    {
        $schedule = app(ScheduleEditService::class);
        $schedule->blockDays(['2026-12-24'], 'Holiday', $this->owner);
        $schedule->blockDays(['2026-12-24'], 'Holiday', $this->owner); // already blocked: nothing new
        $schedule->saveSettings(['buffer_minutes' => 45, 'min_notice_hours' => 24, 'max_advance_days' => 30, 'concurrent_sessions' => 1]);

        $settings = app(ShopSettingsService::class);
        $settings->saveBusiness(['shop_name' => 'Jhunlea', 'address' => 'Secret Street 1', 'phone' => '09171112222', 'email' => 'shop@example.test']);
        $settings->saveBusiness(['shop_name' => 'Jhunlea', 'address' => 'Secret Street 1', 'phone' => '09171112222', 'email' => 'shop@example.test']); // unchanged

        $faqs = app(FaqService::class);
        $faq = $faqs->create(['question' => 'Do you print on Sundays?', 'answer' => 'Yes.', 'is_published' => true]);
        $faqs->setPublished($faq, true); // already published: nothing
        $faqs->setPublished($faq, false);
        $faqs->delete($faq->id);
        $faqs->delete($faq->id); // a second click finds nothing

        $service = PrintingService::factory()->published()->create(['name' => 'Photo Printing']);
        $this->post(route('owner.services.publish', $service))->assertRedirect();

        $this->assertSame(['schedule.date_blocked', 'schedule.rules_changed', 'settings.changed', 'faq.created', 'faq.unpublished', 'faq.deleted', 'catalog.unpublished'], $this->actions());

        $settingsRow = AuditLog::query()->where('action', 'settings.changed')->sole();
        $this->assertEqualsCanonicalizing(['shop_name', 'address', 'phone', 'email'], $settingsRow->meta['keys']);
        $this->assertSame(['shop_name'], array_keys($settingsRow->meta['changes']), 'Contact values are never kept');
        $this->assertStringNotContainsString('Secret Street', json_encode($settingsRow->toArray()));
        $this->assertSame(45, AuditLog::query()->where('action', 'schedule.rules_changed')->sole()->meta['changes']['buffer_minutes']['new']);
    }

    public function test_sign_in_rows_and_failed_owner_logins_are_rate_limited(): void
    {
        auth()->logout();

        $this->post(route('login'), ['email' => 'owner@example.test', 'password' => 'wrong-1'])->assertSessionHasErrors();
        $this->post(route('login'), ['email' => 'owner@example.test', 'password' => 'wrong-2'])->assertSessionHasErrors();
        $this->post(route('login'), ['email' => 'nobody@example.test', 'password' => 'wrong'])->assertSessionHasErrors();
        $this->assertSame(['auth.login_failed'], $this->actions(), 'One row per IP in the window; unknown emails write nothing');
        $failed = AuditLog::query()->sole();
        $this->assertSame(['System', 'system'], [$failed->actor_name, $failed->actor_role]);
        $this->assertStringNotContainsString('owner@example.test', json_encode($failed->toArray()));
        $this->assertStringNotContainsString('wrong', json_encode($failed->toArray()));

        $this->post(route('login'), ['email' => 'owner@example.test', 'password' => 'Owner-Pass-1234'])->assertRedirect();
        $this->assertSame('auth.login', AuditLog::query()->latest('id')->first()->action->value);

        auth()->logout();
        $customer = User::factory()->create(['email' => 'ana@example.test', 'password' => 'Customer-Pass-1']);
        $this->post(route('login'), ['email' => 'ana@example.test', 'password' => 'Customer-Pass-1'])->assertRedirect();
        $this->post(route('logout'));
        $this->post(route('login'), ['email' => 'ana@example.test', 'password' => 'bad'])->assertSessionHasErrors();
        $this->assertSame(2, AuditLog::query()->count(), 'Customer sign-ins are not logged');
        $this->assertNotNull($customer);
    }

    public function test_the_actor_snapshot_survives_deleting_the_account(): void
    {
        $second = User::factory()->owner()->create(['name' => 'Second Owner']);
        app(AuditLogger::class)->record(AuditAction::LoggedIn, actor: $second);
        $second->delete();

        $row = AuditLog::query()->sole();
        $this->assertNull($row->actor_id);
        $this->assertSame(['Second Owner', 'owner'], [$row->actor_name, $row->actor_role]);
    }

    public function test_the_chain_verifies_and_any_edit_delete_or_reorder_is_detected(): void
    {
        $order = $this->order();
        $payment = app(PaymentService::class)->record($order, $this->owner, PaymentMethod::Cash);
        app(PaymentService::class)->void($order, $payment, $this->owner, 'Mistake');
        app(AuditLogger::class)->record(AuditAction::LoggedIn);
        $this->artisan('audit:verify')->assertSuccessful();
        $ids = AuditLog::query()->orderBy('id')->pluck('id')->all();

        // Edited
        DB::table('audit_logs')->where('id', $ids[1])->update(['summary' => 'Voided nothing.']);
        $this->artisan('audit:verify')->assertFailed();
        $this->assertSame($ids[1], app(AuditChain::class)->verify()['broken_id']);
        $this->assertFalse(app(AuditChain::class)->quick()['ok']);
    }

    public function test_a_deleted_or_reordered_row_is_detected(): void
    {
        $this->changesSchema(); // drops or truncates a table: see Tests\TestCase (9B)
        foreach (range(1, 4) as $i) {
            app(AuditLogger::class)->record(AuditAction::LoggedIn);
        }
        $ids = AuditLog::query()->orderBy('id')->pluck('id')->all();
        DB::table('audit_logs')->where('id', $ids[1])->delete();
        $this->assertSame($ids[2], app(AuditChain::class)->verify()['broken_id']);

        DB::table('audit_logs')->truncate();
        foreach (range(1, 3) as $i) {
            app(AuditLogger::class)->record(AuditAction::EndOfDayOpened, null, ['date' => "2026-11-0{$i}"]);
        }
        $rows = DB::table('audit_logs')->orderBy('id')->get();
        // Swap the content of two rows (a reorder)
        DB::table('audit_logs')->where('id', $rows[0]->id)->update((array) collect($rows[1])->except('id')->all());
        DB::table('audit_logs')->where('id', $rows[1]->id)->update((array) collect($rows[0])->except('id')->all());
        $this->assertFalse(app(AuditChain::class)->verify()['ok']);
    }

    public function test_the_model_refuses_every_update_and_delete(): void
    {
        $row = app(AuditLogger::class)->record(AuditAction::LoggedIn);

        foreach ([fn () => $row->forceFill(['summary' => 'x'])->save(), fn () => $row->delete(), fn () => AuditLog::query()->update(['summary' => 'x']), fn () => AuditLog::query()->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('An audit row was changed');
            } catch (LogicException) {
            }
        }

        $this->assertSame(1, AuditLog::query()->count());
        $this->assertSame('Signed in.', AuditLog::query()->value('summary'));
    }

    public function test_reports_receipts_and_account_changes_are_logged(): void
    {
        $order = $this->order();
        $payment = app(PaymentService::class)->record($order, $this->owner, PaymentMethod::Cash);

        $this->get(route('owner.payments.receipt', $payment))->assertOk();
        $this->get(route('owner.payments.receipt', $payment))->assertOk(); // the same day: one row
        $this->get(route('owner.reports.end-of-day'))->assertOk();
        $this->get(route('owner.reports.csv', ['view' => 'daily']))->assertOk()->streamedContent();
        $this->post(route('owner.account.password'), ['current_password' => 'Owner-Pass-1234', 'password' => 'New-Owner-Pass-99', 'password_confirmation' => 'New-Owner-Pass-99'])->assertRedirect();

        $this->assertSame(['payment.recorded', 'payment.receipt_opened', 'report.end_of_day_opened', 'report.csv_exported', 'account.password_changed'], $this->actions());
        $this->assertSame(1, AuditLog::query()->where('action', 'report.csv_exported')->value('meta')['rows']);
        $this->assertStringNotContainsString('New-Owner-Pass', json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_the_activity_page_is_owner_only_with_filters_search_and_no_n_plus_one(): void
    {
        $order = $this->order();
        app(PaymentService::class)->record($order, $this->owner, PaymentMethod::Cash);
        app(FaqService::class)->create(['question' => 'Open on 100% of holidays?', 'answer' => 'No.', 'is_published' => true]);

        $this->get(route('owner.activity.index'))->assertOk()
            ->assertSee('Last check: chain intact.')->assertSee('Activity is recorded from')
            ->assertSee('Jhun Owner recorded', false)->assertSee($order->reference_number)
            ->assertSee(route('owner.payments.receipt', AuditLog::query()->where('action', 'payment.recorded')->value('subject_id')));

        $this->get(route('owner.activity.index', ['group' => 'faq']))->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('owner.activity.index', ['q' => $order->reference_number]))->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        // % and _ are never wildcards: they never match every row (MySQL finds the one row holding "%"; SQLite has no
        // default LIKE escape and finds none, like the existing ledger search)
        $this->get(route('owner.activity.index', ['q' => '%']))->assertViewHas('rows', fn ($rows) => $rows->total() <= 1);
        $this->get(route('owner.activity.index', ['q' => '_']))->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->get(route('owner.activity.index', ['range' => 'yesterday']))->assertOk()->assertSee('No activity found');
        $this->get(route('owner.activity.index', ['group' => 'nope']))->assertRedirect(route('owner.activity.index'))->assertSessionHasErrors();

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('owner.activity.index'))->assertOk();

            return count(DB::getQueryLog());
        };
        $few = $count();
        foreach (range(1, 8) as $i) {
            app(PaymentService::class)->record($this->order(), $this->owner, PaymentMethod::Cash);
        }
        $this->assertSame($few, $count());

        foreach ([route('owner.activity.index'), route('owner.activity.csv')] as $url) {
            // A fresh session: the session guard remembers the owner's password hash and would sign the customer out
            $this->flushSession();
            $this->actingAs($this->customer)->get($url)->assertForbidden();
            auth()->logout();
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_the_csv_export(): void
    {
        $this->owner->forceFill(['name' => '=HYPERLINK("x")'])->save();
        app(AuditLogger::class)->record(AuditAction::LoggedIn, actor: $this->owner);

        $response = $this->get(route('owner.activity.csv', ['range' => 'all']))->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = array_map('str_getcsv', explode("\n", trim(substr($csv, 3))));
        $this->assertSame(ActivityLogController::CSV_COLUMNS, $rows[0]);
        $this->assertStringStartsWith("'=HYPERLINK", $rows[1][1]);
        $this->assertSame('Signed in', $rows[1][2]);

        // The export itself is logged, with the filters and the row count
        $export = AuditLog::query()->where('action', 'audit.exported')->sole();
        $this->assertSame(1, $export->meta['rows']);
    }

    public function test_prune_dry_run_force_and_checkpoint_keep_the_chain_verifiable(): void
    {
        Carbon::setTestNow(Carbon::now('UTC')->subDays(800));
        app(AuditLogger::class)->record(AuditAction::LoggedIn);
        $lastOld = app(AuditLogger::class)->record(AuditAction::LoggedIn)->id;
        Carbon::setTestNow();
        app(AuditLogger::class)->record(AuditAction::EndOfDayOpened, null, ['date' => '2026-11-12']);

        $this->artisan('audit:prune --dry-run')->expectsOutputToContain('Rows older than that: 2')->assertSuccessful();
        $this->assertSame(3, AuditLog::query()->count());
        $this->artisan('audit:prune')->expectsOutputToContain('--force')->assertSuccessful();
        $this->assertSame(3, AuditLog::query()->count());

        $this->artisan('audit:prune --force')->assertSuccessful();
        $this->assertSame(['report.end_of_day_opened', 'audit.pruned'], $this->actions());
        // The id of the last old row (not "2": MySQL never reuses ids of rolled-back rows, 9B)
        $this->assertSame($lastOld, AuditLog::query()->where('action', 'audit.pruned')->value('meta')['pruned_up_to_id']);
        $this->artisan('audit:verify')->assertSuccessful();

        // Prune never touches the head: it still points at the newest row, the checkpoint
        $this->assertHeadIsNewest();
        $this->assertTrue(app(AuditChain::class)->quick()['ok']);
        $this->assertSame(1, DB::table('audit_chain_head')->count());
    }

    private function assertHeadIsNewest(): void
    {
        $head = DB::table('audit_chain_head')->where('id', 1)->first();
        $newest = DB::table('audit_logs')->orderByDesc('id')->first();
        $this->assertNotNull($head);
        $this->assertSame([(int) $newest->id, $newest->hash], [(int) $head->last_id, $head->last_hash]);
    }

    /*
     * The chain head (audit_chain_head, one row, id 1) is the write lock: AuditLogger reads it with lockForUpdate,
     * chains the new row to its hash and moves it forward in the same transaction. Real concurrency (two writers
     * waiting on that lock) cannot be tested here: SQLite locks the whole database and ignores FOR UPDATE, and the
     * tests run in one connection. These tests prove the head's bookkeeping; the lock itself is MySQL's row lock.
     */

    public function test_the_head_advances_with_every_write_and_equals_the_newest_row(): void
    {
        $this->assertSame([null, AuditLogger::genesis()], [DB::table('audit_chain_head')->value('last_id'), DB::table('audit_chain_head')->value('last_hash')]);

        foreach (range(1, 3) as $i) {
            $row = app(AuditLogger::class)->record(AuditAction::LoggedIn);
            $this->assertHeadIsNewest();
            $this->assertSame($row->id, (int) DB::table('audit_chain_head')->value('last_id'));
        }

        $this->post(route('owner.services.publish', PrintingService::factory()->published()->create()))->assertRedirect();
        $this->assertHeadIsNewest();
        $this->assertSame(1, DB::table('audit_chain_head')->count());
    }

    public function test_the_first_write_on_an_empty_table_links_to_the_genesis(): void
    {
        $this->assertTrue(app(AuditChain::class)->verify()['ok'], 'An empty table with a head pointing nowhere is valid');
        $this->assertTrue(app(AuditChain::class)->quick()['ok']);

        $first = app(AuditLogger::class)->record(AuditAction::LoggedIn);
        $this->assertSame(AuditLogger::genesis(), $first->prev_hash);
        $this->artisan('audit:verify')->assertSuccessful();

        // A missing head row is created with the genesis instead of crashing; verify then reports it, since rows exist
        DB::table('audit_chain_head')->delete();
        $this->assertSame('The chain head row is missing.', app(AuditChain::class)->verify()['message']);
        $this->assertNotNull(app(AuditLogger::class)->record(AuditAction::LoggedIn));
        $this->assertSame(1, DB::table('audit_chain_head')->count());
    }

    public function test_deleting_the_newest_row_is_detected_by_the_head(): void
    {
        foreach (range(1, 3) as $i) {
            app(AuditLogger::class)->record(AuditAction::LoggedIn);
        }
        $newest = DB::table('audit_logs')->max('id');

        // The rest still chains, so only the head can see this
        DB::table('audit_logs')->where('id', $newest)->delete();

        $this->artisan('audit:verify')->expectsOutputToContain(AuditChain::HEAD_MISMATCH)->assertFailed();
        $quick = app(AuditChain::class)->quick();
        $this->assertFalse($quick['ok']);
        $this->assertSame(AuditChain::HEAD_MISMATCH, $quick['message']);

        // Emptying the table while the head still points at a row is also broken
        DB::table('audit_logs')->delete();
        $this->assertFalse(app(AuditChain::class)->verify()['ok']);
    }

    public function test_a_catalog_change_and_its_audit_row_are_one_transaction(): void
    {
        $service = PrintingService::factory()->published()->create(['name' => 'Photo Printing']);
        $this->app->instance(AuditLogger::class, new class(app(Redactor::class)) extends AuditLogger
        {
            public function record(AuditAction $action, ?Model $subject = null, array $meta = [], ?string $summary = null, ?User $actor = null, ?string $subjectLabel = null): ?AuditLog
            {
                throw new RuntimeException('audit write failed');
            }
        });

        // The audit write fails, so the delete and the publish change are undone with it
        $this->delete(route('owner.services.destroy', $service))->assertServerError();
        $this->assertNotSoftDeleted($service);
        $this->post(route('owner.services.publish', $service))->assertServerError();
        $this->assertTrue($service->fresh()->is_published);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_a_failure_to_log_outside_a_transaction_never_breaks_the_action(): void
    {
        $faq = new Faq;
        $faq->forceFill(['question' => 'Q?', 'answer' => 'A.', 'is_published' => true, 'position' => 1])->save();
        // Every audit write fails from here. A listener, not DROP TABLE: MySQL commits DDL at once, which would end the
        // test's own transaction and really drop the table of the test database (9B)
        AuditLog::creating(fn () => throw new RuntimeException('The audit table is unavailable.'));

        // Outside a transaction (a CSV, a receipt, a sign-in): the action goes on, the failure is only logged
        $outside = new class(app(Redactor::class)) extends AuditLogger
        {
            protected function inTransaction(): bool
            {
                return false;
            }
        };
        $this->assertNull($outside->record(AuditAction::LoggedIn));

        // Inside a transaction: the action fails with it
        $this->expectException(\Throwable::class);
        app(FaqService::class)->setPublished($faq, false);
    }
}
