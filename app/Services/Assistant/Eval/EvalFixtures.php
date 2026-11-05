<?php

namespace App\Services\Assistant\Eval;

use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\PriceGuard;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The fake people of the eval (Phase 7E-2b), built in the sandbox with the project's own factories and the seeded
 * catalog: "guest" (no account), "ana" (a ready order, an order awaiting its price, a requested booking whose time
 * passed two days ago, a confirmed future booking, a completed paid booking and a completed paid order), "ben" (other
 * records, to prove Ana never sees them) and "newbie" (no records). Reference numbers come from the factories, so the
 * cases name them through placeholders such as {{ana.ready_order}}.
 */
class EvalFixtures
{
    /** @var array<string, User> */
    private array $customers = [];

    /** @var array<string, string> placeholder => value */
    private array $placeholders = [];

    /** @var array<string, array<string, array{type: string, id: int}>> scenario => reference => record */
    private array $records = ['guest' => [], 'ana' => [], 'ben' => [], 'newbie' => [], 'owner' => []];

    // Phase 7E-3a: the "owner" scenario (actor = the shop owner) and its known data
    private ?User $owner = null;

    public function build(EvalSandbox $sandbox): void
    {
        $sandbox->assertActive();

        $owner = User::factory()->owner()->create(['name' => 'Eval Owner', 'email' => 'eval-owner@example.test']);
        $ana = User::factory()->create(['name' => 'Ana Evaluacion', 'email' => 'ana@eval.example.test', 'phone' => '09171112233']);
        $ben = User::factory()->create(['name' => 'Ben Halimbawa', 'email' => 'ben@eval.example.test', 'phone' => '09184445566']);
        $newbie = User::factory()->create(['name' => 'Nina Bago', 'email' => 'newbie@eval.example.test', 'phone' => '09197778899']);
        $this->customers = ['ana' => $ana, 'ben' => $ben, 'newbie' => $newbie];

        $documents = $this->service('Document Printing');
        $photos = $this->service('Photo Printing');
        $family = $this->package('Family Portrait');
        $solo = $this->package('Solo Portrait Session');
        $graduation = $this->package('Graduation Portraits');
        $manila = fn (string $at) => Carbon::parse($at, 'Asia/Manila')->utc();

        $this->add('ana', 'ready_order', $this->order($ana, $documents, PrintingOrderStatus::ReadyForPickup, '150.00'));
        $this->add('ana', 'price_order', $this->order($ana, $photos, PrintingOrderStatus::UnderReview, null, '90.00'));
        $this->add('ana', 'past_booking', $this->booking($ana, $family, BookingStatus::Requested, $manila('2026-11-03 10:00'), null));
        $this->add('ana', 'future_booking', $this->booking($ana, $family, BookingStatus::Confirmed, $manila('2026-11-09 14:00'), (string) $family->price));
        $doneBooking = $this->booking($ana, $solo, BookingStatus::Completed, $manila('2026-10-20 10:00'), (string) $solo->price);
        $doneOrder = $this->order($ana, $photos, PrintingOrderStatus::Completed, '75.00');
        $this->pay($doneBooking, $owner);
        $this->pay($doneOrder, $owner);
        $this->add('ana', 'done_booking', $doneBooking);
        $this->add('ana', 'done_order', $doneOrder);

        $this->add('ben', 'order', $this->order($ben, $photos, PrintingOrderStatus::ReadyForPickup, '60.00'));
        $this->add('ben', 'booking', $this->booking($ben, $graduation, BookingStatus::Confirmed, $manila('2026-11-12 09:00'), (string) $graduation->price));

        foreach (['ana' => $ana, 'ben' => $ben, 'newbie' => $newbie] as $scenario => $user) {
            $this->placeholders["{{{$scenario}.name}}"] = $user->name;
            $this->placeholders["{{{$scenario}.phone}}"] = (string) $user->phone;
            $this->placeholders["{{{$scenario}.email}}"] = $user->email;
        }

        foreach (EvalSandbox::BUSINESS as $key => $value) {
            $this->placeholders['{{shop.'.$key.'}}'] = $value;
        }

        $this->owner = $owner;
        $ownerData = new OwnerFixtures;
        $ownerData->build($owner, $this->customers);
        $this->placeholders += $ownerData->placeholders();
        $this->records['owner'] = $ownerData->records();
    }

    /** The shop owner, the actor of the owner cases (7E-3a). */
    public function owner(): User
    {
        return $this->owner ?? throw new RuntimeException('The eval fixtures are not built.');
    }

    public function actor(string $scenario): AssistantActor
    {
        return match ($scenario) {
            'guest' => AssistantActor::guest('eval-guest'),
            'owner' => AssistantActor::user($this->owner()->id),
            default => AssistantActor::user($this->customer($scenario)->id),
        };
    }

    public function customer(string $scenario): User
    {
        return $this->customers[$scenario] ?? throw new RuntimeException("Unknown eval scenario {$scenario}.");
    }

    /** @return array<string, string> */
    public function placeholders(): array
    {
        return $this->placeholders;
    }

    /** @return array<string, array<string, array{type: string, id: int}>> */
    public function records(): array
    {
        return $this->records;
    }

    /** Fills the placeholders of a text; inside a regular expression the value is quoted. */
    public function fill(string $text, bool $regex = false): string
    {
        return strtr($text, $regex ? array_map(fn (string $value) => preg_quote($value, '~'), $this->placeholders) : $this->placeholders);
    }

    /** The case with every placeholder filled in, in the message, the history, the fixed reply and the expectations. */
    public function resolve(EvalCase $case): EvalCase
    {
        $expect = [];

        foreach ($case->expect as $check => $value) {
            $regex = $check === 'reply_not_contains';
            $expect[$check] = $this->fillValue($value, $regex);
        }

        $filled = new EvalCase($case->id, $case->category, $case->severity, $case->scenario, $case->feature,
            array_map(fn (array $turn) => ['role' => $turn['role'], 'text' => $this->fill($turn['text'])], $case->history),
            $this->fill($case->message), $expect, $case->now, $case->reply === null ? null : $this->fill($case->reply));

        $unknown = $this->unknownPlaceholders(json_encode([$filled->message, $filled->history, $filled->reply, $filled->expect]) ?: '');

        if ($unknown !== []) {
            throw new RuntimeException("Case {$case->id} uses unknown placeholders: ".implode(', ', $unknown));
        }

        return $filled;
    }

    /** The facts the checks of a case need, for its scenario. */
    public function context(EvalCase $case, PriceGuard $prices): EvalContext
    {
        $own = $this->records[$case->scenario] ?? [];
        $foreign = [];

        foreach ($this->records as $scenario => $records) {
            if ($scenario !== $case->scenario) {
                array_push($foreign, ...array_keys($records));
            }
        }

        $texts = [$case->message, ...array_column($case->history, 'text')];

        foreach (PrintingService::query()->published()->with('options')->get() as $service) {
            $texts[] = $service->base_price === null ? '' : '₱'.$service->base_price;
            foreach ($service->options as $option) {
                $texts[] = '₱'.$option->price_modifier;
            }
        }

        foreach (PhotographyPackage::query()->published()->get() as $package) {
            $texts[] = $package->price === null ? '' : '₱'.$package->price;
        }

        if (! in_array($case->scenario, ['guest', 'owner'], true)) {
            $customer = $this->customer($case->scenario);
            foreach ([PrintingOrder::class, PhotographyBooking::class] as $model) {
                foreach ($model::query()->where('customer_id', $customer->id)->with('recordedPayment')->get() as $record) {
                    $texts[] = '₱'.$record->estimated_price.' ₱'.$record->confirmed_price.' ₱'.($record->recordedPayment?->amount ?? '');
                }
            }
        }

        return new EvalContext($own, array_values(array_unique($foreign)), $prices->amounts(implode("\n", $texts)));
    }

    private function fillValue(mixed $value, bool $regex): mixed
    {
        return match (true) {
            is_string($value) => $this->fill($value, $regex),
            is_array($value) => array_map(fn ($item) => $this->fillValue($item, $regex), $value),
            default => $value,
        };
    }

    /** @return list<string> */
    private function unknownPlaceholders(string $text): array
    {
        preg_match_all('/\{\{[a-z_.]+\}\}/', stripslashes($text), $matches);

        return array_values(array_unique($matches[0]));
    }

    private function add(string $scenario, string $name, PrintingOrder|PhotographyBooking $record): void
    {
        $this->placeholders["{{{$scenario}.{$name}}}"] = $record->reference_number;
        $this->records[$scenario][$record->reference_number] = ['type' => $record->getMorphClass(), 'id' => $record->id];
    }

    private function service(string $name): PrintingService
    {
        return PrintingService::query()->where('name', $name)->firstOrFail();
    }

    private function package(string $name): PhotographyPackage
    {
        return PhotographyPackage::query()->where('name', $name)->firstOrFail();
    }

    private function order(User $customer, PrintingService $service, PrintingOrderStatus $status, ?string $confirmed, ?string $estimate = null): PrintingOrder
    {
        return PrintingOrder::factory()->forCustomer($customer)->status($status)->create([
            'printing_service_id' => $service->id,
            'snapshot' => ['service' => ['name' => $service->name, 'pricing_type' => $service->pricing_type->value, 'base_price' => $service->base_price], 'options' => [], 'pages' => 1, 'unit_price' => $service->base_price],
            'confirmed_price' => $confirmed,
            'estimated_price' => $estimate ?? $confirmed,
        ]);
    }

    private function booking(User $customer, PhotographyPackage $package, BookingStatus $status, Carbon $start, ?string $confirmed): PhotographyBooking
    {
        return PhotographyBooking::factory()->forCustomer($customer)->status($status)->at($start, (int) ($package->duration_minutes ?: 60))->create([
            'photography_package_id' => $package->id,
            'snapshot' => ['package' => ['name' => $package->name, 'duration_minutes' => $package->duration_minutes, 'price_case' => 'fixed', 'price_text' => $package->priceText(), 'inclusions' => $package->inclusions ?? []], 'theme' => null],
            'confirmed_price' => $confirmed,
            'estimated_price' => $package->price,
        ]);
    }

    private function pay(PrintingOrder|PhotographyBooking $record, User $owner): void
    {
        Payment::factory()->paying($record)->create(['received_by' => $owner->id]);
    }
}
