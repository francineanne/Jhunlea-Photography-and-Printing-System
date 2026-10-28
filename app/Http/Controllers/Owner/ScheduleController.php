<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\Schedule\BlockDatesRequest;
use App\Http\Requests\Owner\Schedule\CalendarRequest;
use App\Http\Requests\Owner\Schedule\SaveHoursRequest;
use App\Http\Requests\Owner\Schedule\SaveSettingsRequest;
use App\Http\Requests\Owner\Schedule\UnblockDateRequest;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\BookingSetting;
use App\Models\PhotographyBooking;
use App\Services\ScheduleCalendarService;
use App\Services\ScheduleEditService;
use App\Services\ScheduleImpactService;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The owner's schedule (Phase 4D): the calendar, and the editor of the opening hours, booking settings and blocked
 * dates. A change that would leave confirmed future bookings outside the rules is shown first and saved only after the
 * owner ticks "save anyway"; no booking is ever cancelled or moved by it.
 */
class ScheduleController extends Controller
{
    public function __construct(
        private readonly ScheduleEditService $edits,
        private readonly ScheduleImpactService $impacts,
    ) {}

    public function index(CalendarRequest $request, ScheduleCalendarService $calendar): View
    {
        return view('owner.schedule.index', [
            'calendar' => $calendar->build($request->calendarView(), $request->calendarDate()),
            'rules' => $this->rules(),
            'settings' => BookingSetting::current(),
            'upcomingBlocked' => $this->upcomingBlocked(),
        ]);
    }

    public function edit(): View
    {
        return view('owner.schedule.edit', [
            'rules' => $this->rules(),
            'settings' => BookingSetting::current(),
            'upcomingBlocked' => $this->upcomingBlocked(),
        ]);
    }

    public function hours(SaveHoursRequest $request): RedirectResponse
    {
        $impact = $this->impacts->forHours($request->hours());

        if ($impact['confirmed']->isNotEmpty() && ! $request->acknowledged()) {
            return $this->needsAcknowledgement('hours', $impact, 'Some confirmed sessions would fall outside the new opening hours.');
        }

        $this->edits->saveHours($request->hours());

        return $this->saved('hours', $impact, 'Opening hours saved. The booking calendar uses them now.');
    }

    public function settings(SaveSettingsRequest $request): RedirectResponse
    {
        $settings = $request->settings();
        $impact = $this->impacts->forSettings($settings['buffer_minutes'], $settings['concurrent_sessions']);

        if ($impact['confirmed']->isNotEmpty() && ! $request->acknowledged()) {
            return $this->needsAcknowledgement('settings', $impact, 'Some confirmed sessions would overlap more than the new settings allow.');
        }

        $this->edits->saveSettings($settings);

        return $this->saved('settings', $impact, 'Booking settings saved. The booking calendar uses them now.');
    }

    public function block(BlockDatesRequest $request): RedirectResponse
    {
        $days = $request->days();
        $impact = $this->impacts->forBlockedDays($days);

        if ($impact['confirmed']->isNotEmpty() && ! $request->acknowledged()) {
            return $this->needsAcknowledgement('blocked', $impact, 'Some confirmed sessions are on the days you want to block.');
        }

        $added = $this->edits->blockDays($days, $request->reason(), $request->user());
        $skipped = count($days) - $added;

        return $this->saved('blocked', $impact, ($added === 1 ? '1 day blocked.' : "{$added} days blocked.").($skipped > 0 ? " {$skipped} ".($skipped === 1 ? 'was' : 'were').' already blocked.' : ''));
    }

    public function unblock(UnblockDateRequest $request, BlockedDate $blockedDate): RedirectResponse
    {
        $day = $blockedDate->date->format('Y-m-d');
        $this->edits->unblock($blockedDate);

        return redirect()->to(url()->previous(route('owner.schedule.edit')).'#blocked')
            ->with('status', LocalTime::format(Carbon::parse($day, config('app.display_timezone')), 'D, M j, Y').' is open for bookings again.');
    }

    /** @param  array{confirmed: Collection<int, PhotographyBooking>, pending: Collection<int, PhotographyBooking>}  $impact */
    private function needsAcknowledgement(string $section, array $impact, string $why): RedirectResponse
    {
        return redirect()->to(url()->previous(route('owner.schedule.edit')).'#'.$section)
            ->withInput()
            ->with('impact', ['section' => $section, 'needsTick' => true] + $this->listed($impact))
            ->with('error', $why.' Nothing was saved. Look at the list, then tick “Save anyway” to save.');
    }

    /** @param  array{confirmed: Collection<int, PhotographyBooking>, pending: Collection<int, PhotographyBooking>}  $impact */
    private function saved(string $section, array $impact, string $done): RedirectResponse
    {
        $back = redirect()->to(url()->previous(route('owner.schedule.edit')).'#'.$section)->with('status', $done);

        // What the change left outside the rules, for the owner to follow up; nothing was changed on those bookings
        if ($impact['confirmed']->isNotEmpty() || $impact['pending']->isNotEmpty()) {
            $back->with('impact', ['section' => $section, 'needsTick' => false] + $this->listed($impact));
        }

        return $back;
    }

    /**
     * The bookings as plain values for the session, so the next page lists them without another query.
     *
     * @param  array{confirmed: Collection<int, PhotographyBooking>, pending: Collection<int, PhotographyBooking>}  $impact
     * @return array{confirmed: list<array<string, mixed>>, pending: list<array<string, mixed>>}
     */
    private function listed(array $impact): array
    {
        $row = fn (PhotographyBooking $booking) => [
            'id' => $booking->id,
            'reference' => $booking->reference_number,
            'customer' => $booking->customer?->name ?? 'Deleted account',
            'status' => $booking->status->label(),
            'starts_at' => $booking->starts_at->toIso8601ZuluString(),
        ];

        return ['confirmed' => $impact['confirmed']->map($row)->all(), 'pending' => $impact['pending']->map($row)->all()];
    }

    /** @return Collection<int, AvailabilityRule> every weekday, keyed 0 (Sunday) to 6, a closed placeholder where no row exists */
    private function rules(): Collection
    {
        $saved = AvailabilityRule::query()->get()->keyBy('weekday');

        return collect(range(0, 6))->mapWithKeys(fn (int $weekday) => [$weekday => $saved->get($weekday) ?? tap(new AvailabilityRule(['is_open' => false]), fn ($rule) => $rule->weekday = $weekday)]);
    }

    /** @return Collection<int, BlockedDate> */
    private function upcomingBlocked(): Collection
    {
        return BlockedDate::query()
            ->where('date', '>=', LocalTime::format(Carbon::now('UTC'), 'Y-m-d'))
            ->orderBy('date')
            ->get();
    }
}
