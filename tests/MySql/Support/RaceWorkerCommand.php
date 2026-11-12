<?php

namespace Tests\MySql\Support;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\PrintingOrderStatus;
use App\Exceptions\BookingTransitionException;
use App\Exceptions\ChatException;
use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\PaymentException;
use App\Models\Conversation;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\BookingService;
use App\Services\BookingTransitionService;
use App\Services\Chat\ConversationService;
use App\Services\Chat\MessageService;
use App\Services\PaymentService;
use App\Services\ReferenceNumberService;
use App\Services\StatusTransitionService;
use App\Services\WalkIn\WalkInCustomerService;
use App\Services\WalkIn\WalkInOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDOException;
use Tests\Support\TestDatabaseGuard;
use Throwable;

/**
 * Phase 9B, TEST ONLY: one worker of a race (tests/MySql/Support/Race.php). Registered by routes/console.php only in
 * the testing environment and only when the dev autoloader has this class. It refuses to run unless the MySQL test
 * marker is set and the LIVE connection is a database ending with "_test". It waits for the shared start time, does
 * its action through the real services (never raw SQL), and prints ONE JSON line.
 */
class RaceWorkerCommand extends Command
{
    protected $signature = 'jhunlea:race-worker {scenario} {--start=} {--worker=0} {--payload=}';

    protected $description = 'Test only: one worker of a MySQL race test';

    protected $hidden = true;

    public function handle(): int
    {
        if (! TestDatabaseGuard::mysqlMode() || ! str_ends_with(TestDatabaseGuard::liveDatabaseName($this->laravel), '_test')) {
            $this->line(json_encode(['error' => 'refused: not the MySQL test database']));

            return self::FAILURE;
        }

        $payload = json_decode((string) base64_decode((string) $this->option('payload')), true) ?: [];
        DB::connection()->getPdo(); // connected before the start, so the race is about the work, not the connection

        $start = (float) $this->option('start');
        $late = microtime(true) > $start;
        while (microtime(true) < $start) {
            // spin: usleep on Windows sleeps at least 1 ms; the last stretch is a busy wait for a tight start
            if ($start - microtime(true) > 0.01) {
                usleep(2000);
            }
        }

        $results = match ($this->argument('scenario')) {
            'audit' => $this->audit($payload),
            'pay' => [$this->attempt('pay', fn () => $this->pay($payload))],
            'price' => [$this->attempt('price', fn () => app(StatusTransitionService::class)->changePrice(PrintingOrder::query()->findOrFail($payload['order_id']), $this->owner($payload), $payload['price'])->id)],
            'confirm' => [$this->attempt('confirm', fn () => app(BookingTransitionService::class)->confirm(PhotographyBooking::query()->findOrFail($payload['booking_id']), $this->owner($payload), outsideSchedule: true)->id)],
            'book' => [$this->attempt('book', fn () => $this->book($payload))],
            'studio' => [$this->attempt('studio', fn () => app(ConversationService::class)->studioFor(User::query()->findOrFail($payload['customer_id']))->id)],
            'send' => [$this->attempt('send', fn () => $this->send($payload))],
            'mixed' => $this->mixed($payload),
            default => [['op' => 'unknown', 'outcome' => 'error', 'exception' => 'UnknownScenario']],
        };

        $this->line(json_encode(['worker' => (int) $this->option('worker'), 'late' => $late, 'results' => $results]));

        return self::SUCCESS;
    }

    private function owner(array $payload): User
    {
        return User::query()->findOrFail($payload['owner_id']);
    }

    /**
     * One action: ok (with its value), refused (a rule of the app said no, with the reason) or error (an exception
     * class and the MySQL error code, for example 1213 deadlock or 1205 lock wait timeout).
     *
     * @return array<string, mixed>
     */
    private function attempt(string $op, callable $action): array
    {
        $began = hrtime(true);

        try {
            $value = $action();
            $result = ['outcome' => 'ok', 'value' => $value];
        } catch (PaymentException|InvalidStatusTransitionException|BookingTransitionException|ChatException|AuthorizationException $e) {
            $result = ['outcome' => 'refused', 'reason' => $e->getMessage(), 'exception' => class_basename($e)];
        } catch (ValidationException $e) {
            $result = ['outcome' => 'refused', 'reason' => collect($e->errors())->flatten()->first(), 'exception' => 'ValidationException'];
        } catch (Throwable $e) {
            $code = null;
            if ($e instanceof QueryException || $e instanceof PDOException) {
                $code = $e->errorInfo[1] ?? null;
            }
            // Laravel's DeadlockException (a deadlock inside a nested transaction) carries the code only in its message
            if ($code === null && preg_match('/\b(1213|1205)\b/', $e->getMessage(), $match)) {
                $code = (int) $match[1];
            }
            $result = ['outcome' => 'error', 'exception' => class_basename($e), 'code' => $code, 'reason' => Str::limit($e->getMessage(), 200)];
        }

        return ['op' => $op, 'ms' => round((hrtime(true) - $began) / 1e6, 1)] + $result;
    }

    /** @return list<array<string, mixed>> */
    private function audit(array $payload): array
    {
        $logger = app(AuditLogger::class);
        $results = [];

        foreach (range(1, (int) $payload['count']) as $i) {
            $inner = (bool) $payload['inner'] && $i % 2 === 0;
            $results[] = $this->attempt($inner ? 'audit_in_caller_tx' : 'audit_own_tx', function () use ($logger, $inner, $i) {
                $write = fn () => $logger->record(AuditAction::EndOfDayOpened, null, ['date' => '2026-11-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT)]);
                $row = $inner ? DB::transaction($write) : $write();

                return $row?->id ?? throw new \RuntimeException('AuditLogger returned null (the write failed and was only logged)');
            });
        }

        return $results;
    }

    private function pay(array $payload): int
    {
        $order = PrintingOrder::query()->findOrFail($payload['order_id']);
        $method = PaymentMethod::from($payload['method'] ?? 'cash');

        return app(PaymentService::class)->record($order, $this->owner($payload), $method, $payload['gcash'] ?? null, $payload['expected'] ?? $order->confirmed_price)->id;
    }

    private function book(array $payload): int
    {
        return app(BookingService::class)->place(
            User::query()->findOrFail($payload['customer_id']),
            PhotographyPackage::query()->findOrFail($payload['package_id']),
            null,
            'Race test idea',
            Carbon::parse($payload['starts_at'], 'UTC'),
            1,
            '09171234567',
            null,
            false,
        )->id;
    }

    /** @return array{id: int, stored_now: bool} */
    private function send(array $payload): array
    {
        [$message, $storedNow] = app(MessageService::class)->sendFromCustomer(
            Conversation::query()->findOrFail($payload['conversation_id']),
            User::query()->findOrFail($payload['customer_id']),
            $payload['body'],
            $payload['client_id'],
        );

        return ['id' => (int) $message->id, 'stored_now' => $storedNow];
    }

    /**
     * Random actions for a while on a small set of orders: payment, void, status change, price change, walk-in order,
     * audit only. Every action goes through its service, exactly as the owner's buttons do.
     *
     * @return list<array<string, mixed>>
     */
    private function mixed(array $payload): array
    {
        mt_srand((int) $payload['seed']);
        $owner = $this->owner($payload);
        $until = microtime(true) + (float) $payload['seconds'];
        $results = [];

        while (microtime(true) < $until) {
            $order = PrintingOrder::query()->find($payload['order_ids'][mt_rand(0, count($payload['order_ids']) - 1)]);
            $op = ['pay', 'void', 'status', 'price', 'walk_in', 'walk_in_new', 'audit'][mt_rand(0, 6)];

            $results[] = $this->attempt($op, match ($op) {
                'pay' => fn () => app(PaymentService::class)->record($order, $owner, PaymentMethod::Cash, null, $order->confirmed_price)->id,
                'void' => function () use ($order, $owner) {
                    $payment = Payment::query()->where('payable_type', $order->getMorphClass())->where('payable_id', $order->id)->where('status', PaymentRecordStatus::Recorded)->first()
                        ?? throw PaymentException::alreadyPaid();

                    return app(PaymentService::class)->void($order, $payment, $owner, 'Race test void')->id;
                },
                'status' => fn () => app(StatusTransitionService::class)->transition($order, match ($order->status) {
                    PrintingOrderStatus::Accepted => PrintingOrderStatus::Processing,
                    PrintingOrderStatus::Processing => PrintingOrderStatus::ReadyForPickup,
                    default => PrintingOrderStatus::Processing,
                }, $owner)->id,
                'price' => fn () => app(StatusTransitionService::class)->changePrice($order, $owner, number_format(mt_rand(100, 900), 2, '.', ''))->id,
                'walk_in' => fn () => app(WalkInOrderService::class)->place(
                    $owner, User::query()->findOrFail($payload['walk_in_customer_id']), PrintingService::query()->findOrFail($payload['service_id']),
                    [], 1, 1, null, WalkInOrderService::PRICE_CUSTOM, '150.00', 'Race test price', PaymentMethod::Cash, null, (string) Str::uuid(),
                )['order']->id,
                // As WalkInController::storeOrder does it: a NEW walk-in customer and the order in one transaction
                'walk_in_new' => fn () => DB::transaction(function () use ($owner, $payload) {
                    app(ReferenceNumberService::class)->lock(ReferenceNumberService::PAYMENT);

                    return app(WalkInOrderService::class)->place(
                        $owner, app(WalkInCustomerService::class)->create('Race Walk-in '.Str::random(6), null), PrintingService::query()->findOrFail($payload['service_id']),
                        [], 1, 1, null, WalkInOrderService::PRICE_CUSTOM, '175.00', 'Race test price', PaymentMethod::Cash, null, (string) Str::uuid(),
                    )['order']->id;
                }),
                default => fn () => app(AuditLogger::class)->record(AuditAction::EndOfDayOpened, null, ['date' => '2026-11-12'])?->id ?? throw new \RuntimeException('audit write failed'),
            });
        }

        return $results;
    }
}
