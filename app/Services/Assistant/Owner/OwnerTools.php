<?php

namespace App\Services\Assistant\Owner;

use App\Services\Assistant\Owner\Tools\BookingLookup;
use App\Services\Assistant\Owner\Tools\BookingsSchedule;
use App\Services\Assistant\Owner\Tools\Briefing;
use App\Services\Assistant\Owner\Tools\CatalogPerformance;
use App\Services\Assistant\Owner\Tools\CounterQueues;
use App\Services\Assistant\Owner\Tools\CustomerProfile;
use App\Services\Assistant\Owner\Tools\CustomerSearch;
use App\Services\Assistant\Owner\Tools\HowTo;
use App\Services\Assistant\Owner\Tools\IncomeSummary;
use App\Services\Assistant\Owner\Tools\LumiUsage;
use App\Services\Assistant\Owner\Tools\MessagesOverview;
use App\Services\Assistant\Owner\Tools\OrderLookup;
use App\Services\Assistant\Owner\Tools\OrdersOverview;
use App\Services\Assistant\Owner\Tools\SlotAvailability;
use App\Services\Assistant\Owner\Tools\UnpaidBalances;
use App\Services\Assistant\Owner\Tools\WalkInSummary;

/**
 * THE list of the owner's Ask Lumi tools (Phase 7E-3a). Registering a tool is one line in TOOLS; Phase 8D added the
 * counter and walk-in tools here. Every tool is read-only.
 */
class OwnerTools
{
    /** @var list<class-string<OwnerTool>> */
    public const TOOLS = [
        IncomeSummary::class,
        UnpaidBalances::class,
        CounterQueues::class,
        WalkInSummary::class,
        OrdersOverview::class,
        OrderLookup::class,
        BookingsSchedule::class,
        BookingLookup::class,
        SlotAvailability::class,
        CustomerSearch::class,
        CustomerProfile::class,
        MessagesOverview::class,
        CatalogPerformance::class,
        LumiUsage::class,
        HowTo::class,
        Briefing::class,
    ];

    /** @var array<string, OwnerTool>|null */
    private ?array $tools = null;

    /** @return array<string, OwnerTool> */
    public function all(): array
    {
        if ($this->tools === null) {
            $this->tools = [];

            foreach (self::TOOLS as $class) {
                $tool = app($class);
                $this->tools[$tool->name()] = $tool;
            }
        }

        return $this->tools;
    }

    public function get(string $name): OwnerTool
    {
        return $this->all()[$name] ?? throw new InvalidToolCall('unknown tool '.mb_substr($name, 0, 40));
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /** The catalog the planning call reads (in the stable, cached part of the prompt). */
    public function catalog(): string
    {
        return implode("\n", array_map(fn (OwnerTool $tool) => $tool->catalogLine(), $this->all()));
    }
}
