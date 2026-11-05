<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Models\PhotographyPackage;
use App\Models\User;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;
use App\Services\AvailabilityService;
use App\Support\Slot;
use Illuminate\Support\Carbon;

/**
 * The free session times of one day (AvailabilityService: opening hours, blocked dates, notice, how far ahead, and
 * the bookings that hold time through ScheduleConflictService). It only reads: it never books or holds a time.
 */
class SlotAvailability extends OwnerTool
{
    public function __construct(private readonly AvailabilityService $availability) {}

    public function name(): string
    {
        return 'slot_availability';
    }

    public function description(): string
    {
        return 'The free session start times on one day for a package (its length) or a length in minutes. Reads the schedule only; it never books.';
    }

    public function parameters(): array
    {
        return [
            'date' => ['type' => 'date', 'required' => true, 'description' => 'YYYY-MM-DD'],
            'package' => ['type' => 'string', 'max_length' => 80, 'description' => 'package name'],
            'duration_minutes' => ['type' => 'integer', 'min' => 15, 'max' => 600],
        ];
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $result = new ToolResult($this->name());
        $package = isset($params['package']) ? PhotographyPackage::query()->where('name', 'like', '%'.addcslashes($params['package'], '%_\\').'%')->orderBy('id')->first() : null;

        if (isset($params['package']) && $package === null) {
            $result->question = 'Which package do you mean? I could not find one with that name.';

            return $result;
        }

        $minutes = (int) ($package?->duration_minutes ?? $params['duration_minutes'] ?? 60);
        $date = Carbon::createFromFormat('!Y-m-d', $params['date'], config('app.display_timezone'));

        if ($date === false || $date->format('Y-m-d') !== $params['date']) {
            $result->question = 'Which day do you mean?';

            return $result;
        }

        $slots = $this->availability->slots($minutes, $params['date'], $params['date']);
        $result->total = $slots->count();
        $result->rows = $slots->take(self::MAX_ROWS * 2)->map(fn (Slot $slot) => ['time' => $result->moment($slot->startsAt).' to '.$result->moment($slot->endsAt)])->values()->all();
        $result->data = [
            'day' => $result->day($params['date']),
            'package' => $package?->name,
            'session_minutes' => $result->number($minutes),
            'free_times' => $result->count($slots->count()),
            'note' => 'A free time is not a booking; the customer requests it and the owner confirms.',
        ];
        $result->link('Schedule', route('owner.schedule.index', ['view' => 'day', 'date' => $params['date']]));

        return $result;
    }
}
