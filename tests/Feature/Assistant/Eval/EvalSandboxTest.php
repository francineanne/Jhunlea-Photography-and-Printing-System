<?php

namespace Tests\Feature\Assistant\Eval;

use App\Models\AssistantRun;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Assistant\Eval\EvalFixtures;
use App\Services\Assistant\Eval\EvalSandbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FakesAssistant;
use Tests\Support\RunsEval;
use Tests\TestCase;

/**
 * The eval's throwaway database (Phase 7E-2b): only a .sqlite file inside storage/app/evals, asserted before every
 * write, never the test's (or the real) database; the frozen clock; and the fake customers' records.
 */
class EvalSandboxTest extends TestCase
{
    use FakesAssistant, RefreshDatabase, RunsEval;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
        $this->setUpEval();
    }

    private function sandbox(): EvalSandbox
    {
        return new EvalSandbox((string) config('assistant.eval.database'));
    }

    public function test_only_a_sqlite_file_inside_storage_app_evals_is_accepted(): void
    {
        foreach ([database_path('database.sqlite'), storage_path('app/evals/eval.db'), storage_path('app/evals/../private/x.sqlite'), base_path('eval.sqlite')] as $path) {
            try {
                new EvalSandbox($path);
                $this->fail("Accepted {$path}");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('storage/app/evals', $exception->getMessage());
            }
        }

        $this->assertSame(storage_path('app/evals/eval.sqlite'), EvalSandbox::defaultPath());
    }

    public function test_the_guard_aborts_when_the_active_connection_is_not_the_eval_file(): void
    {
        $sandbox = $this->sandbox();

        // Not opened yet: the active connection is the test database
        try {
            $sandbox->assertActive();
            $this->fail('The guard let the test database through');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing to run the eval', $exception->getMessage());
        }

        $sandbox->open();
        $sandbox->assertActive();

        // Someone switches the default connection away: the guard stops the next write
        config(['database.default' => 'sqlite']);
        DB::setDefaultConnection('sqlite');
        $this->expectException(RuntimeException::class);

        try {
            $sandbox->assertActive();
        } finally {
            config(['database.default' => EvalSandbox::CONNECTION]);
            DB::setDefaultConnection(EvalSandbox::CONNECTION);
            $sandbox->close();
        }
    }

    public function test_it_writes_only_its_own_file_and_removes_it(): void
    {
        $before = DB::connection('sqlite')->table('users')->count();
        $sandbox = $this->sandbox();
        $sandbox->open();

        $this->assertSame(EvalSandbox::CONNECTION, DB::getDefaultConnection());
        $this->assertFileExists($sandbox->path());
        $this->assertGreaterThan(0, DB::table('printing_services')->count(), 'The sample catalog is seeded');
        $this->assertSame(EvalSandbox::BUSINESS['phone'], DB::table('shop_settings')->value('phone'));

        (new EvalFixtures)->build($sandbox);
        // 7E-3a: and the owner cases' three customers (Maria Santos, Maria Reyes, the injection name)
        $this->assertSame(7, User::query()->count(), 'The owner, Ana, Ben, Newbie and the three customers of the owner cases');

        $sandbox->close();

        $this->assertFileDoesNotExist($sandbox->path());
        $this->assertSame('sqlite', DB::getDefaultConnection(), 'The test connection is back');
        $this->assertSame($before, DB::connection('sqlite')->table('users')->count(), 'Nothing reached the test database');
        $this->assertSame(0, DB::connection('sqlite')->table('printing_services')->count());
    }

    public function test_the_clock_is_frozen_on_a_thursday_morning_in_manila_and_the_limits_are_raised(): void
    {
        $sandbox = $this->sandbox();
        $sandbox->open();

        try {
            $this->assertSame('2026-11-05 10:00 Thursday', Carbon::now('Asia/Manila')->format('Y-m-d H:i l'));
            $this->assertGreaterThanOrEqual(1_000_000, config('assistant.limits.per_day_per_user'));
            $this->assertSame('array', config('cache.default'));

            $sandbox->freeze('2026-11-08 23:30');
            $this->assertSame('2026-11-08 15:30', Carbon::now('UTC')->format('Y-m-d H:i'));
        } finally {
            $sandbox->close();
        }

        $this->assertNotSame('2026-11-08 15:30', Carbon::now('UTC')->format('Y-m-d H:i'), 'close() unfreezes the clock');
    }

    public function test_each_fake_customer_owns_only_their_own_records(): void
    {
        $sandbox = $this->sandbox();
        $sandbox->open();

        try {
            $fixtures = new EvalFixtures;
            $fixtures->build($sandbox);
            $records = $fixtures->records();

            $this->assertCount(6, $records['ana']);
            $this->assertCount(2, $records['ben']);
            $this->assertSame([], $records['newbie']);
            $this->assertSame([], $records['guest']);
            $this->assertSame([], array_intersect(array_keys($records['ana']), array_keys($records['ben'])));

            foreach (['ana', 'ben'] as $scenario) {
                $owner = $fixtures->customer($scenario)->id;

                foreach ($records[$scenario] as $reference => $record) {
                    $model = $record['type'] === 'printing_order' ? PrintingOrder::class : PhotographyBooking::class;
                    $row = $model::query()->findOrFail($record['id']);
                    $this->assertSame($reference, $row->reference_number);
                    $this->assertSame($owner, $row->customer_id, "{$reference} belongs to {$scenario}");
                }
            }

            $placeholders = $fixtures->placeholders();
            $past = PhotographyBooking::query()->where('reference_number', $placeholders['{{ana.past_booking}}'])->sole();
            $this->assertSame('requested', $past->status->value);
            $this->assertSame('2026-11-03 10:00', $past->starts_at->setTimezone('Asia/Manila')->format('Y-m-d H:i'), 'Two days before the frozen date');
            $this->assertSame('ready_for_pickup', PrintingOrder::query()->where('reference_number', $placeholders['{{ana.ready_order}}'])->sole()->status->value);
            $this->assertNull(PrintingOrder::query()->where('reference_number', $placeholders['{{ana.price_order}}'])->sole()->confirmed_price);
            $this->assertNotNull(PrintingOrder::query()->where('reference_number', $placeholders['{{ana.done_order}}'])->sole()->recordedPayment);
            // 7E-3a: only the four Lumi runs the owner cases read (lumi_usage); building calls no provider
            $this->assertSame(4, AssistantRun::query()->count());
        } finally {
            $sandbox->close();
        }
    }
}
