<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\AngelType;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;
use Engelsystem\Models\UserAngelType;
use Illuminate\Support\Collection;

/**
 * Shifts until the end of tomorrow that need attention: open slots (like /gaps) or, today, people
 * who haven't checked in at the desk; a day's check-ins per area and half day, and who hasn't
 * checked in; and onboarding progress per area (who connected Telegram, which Engelsystem can't
 * show); shifts, goodies and the log are Engelsystem's own pages. Leads (supporters of any area)
 * aren't counted or listed anywhere, only volunteers are.
 */
class Dashboard
{
    public static function data(?string $day = null): array
    {
        $now = Carbon::now();
        $leads = Roster::leadIds();

        return [
            'now'        => $now,
            'shifts'     => self::shifts($now, $leads),
            'checkins'   => self::checkins($now, $day, $leads),
            'onboarding' => self::onboarding($leads),
            'log_prefix' => Roster::LOG_PREFIX,
        ];
    }

    /**
     * Every shift and area today and tomorrow, also those that are over: open slots (standby isn't
     * counted, as with /gaps) and, for today's shifts, how many on it checked in at the desk
     * (no-shows aren't counted, they are already marked). Leads on the shift count neither as
     * needed nor as filled, see Roster::volunteers(). Started shifts are urgent, as are those
     * starting within three hours, like Engelsystem's public dashboard.
     */
    protected static function shifts(Carbon $now, Collection $leads): Collection
    {
        $shifts = Shift::query()
            ->with(['schedule', 'neededAngelTypes.angelType', 'shiftEntries.angelType'])
            ->where('start', '>=', $now->copy()->startOfDay())
            ->where('start', '<', $now->copy()->addDay()->endOfDay())
            ->orderBy('start')
            ->get();
        $checkedIn = Checkin::query()->where('day', $now->toDateString())->pluck('user_id');
        $standby = Roster::standbyArea();

        $rows = new Collection();
        foreach ($shifts as $shift) {
            $today = $shift->start->isSameDay($now);
            $needed = Roster::needed($shift);
            $areas = $needed->pluck('angelType')->concat($shift->shiftEntries->pluck('angelType'))
                ->unique('id')
                ->sortBy('name');
            foreach ($areas as $area) {
                $entries = $shift->shiftEntries
                    ->where('angel_type_id', $area->id)
                    ->whereNull('freeloaded_by');
                $staffing = Roster::volunteers(
                    (int) $needed->where('angel_type_id', $area->id)->sum('count'),
                    $shift->shiftEntries->where('angel_type_id', $area->id),
                    $leads
                );
                $open = $area->id === $standby?->id ? 0 : max(0, $staffing['needed'] - $staffing['filled']);
                $volunteers = $entries->reject(fn(ShiftEntry $entry) => $leads->contains($entry->user_id));
                $missing = $today
                    ? $volunteers->reject(fn(ShiftEntry $entry) => $checkedIn->contains($entry->user_id))->count()
                    : 0;
                $rows->push([
                    'shift'      => $shift,
                    'angel_type' => $area,
                    ...$staffing,
                    'open'       => $open,
                    'today'      => $today,
                    'checked_in' => $volunteers->count() - $missing,
                    'missing'    => $missing,
                    'over'       => $shift->end <= $now,
                    'urgency'    => match (true) {
                        $shift->end <= $now                        => '',
                        $shift->start <= $now                      => 'danger',
                        $shift->start <= $now->copy()->addHours(3) => 'warning',
                        default                                    => '',
                    },
                ]);
            }
        }

        return $rows;
    }

    /**
     * Who checked in at the desk on a day (today, unless another day with shifts is picked), per
     * area and half day of their shifts (AM starts before noon, as in the import), and everyone on
     * a shift that day who didn't; no-shows aren't counted, they are already marked
     *
     * @return array{days: array<string, string>, day: Carbon, areas: Collection, total: array, missing: Collection, without_shift: int}
     */
    protected static function checkins(Carbon $now, ?string $day, Collection $leads): array
    {
        $days = Checkin::days();
        $date = Checkin::pickDay($days, $day, $now);

        $onDay = ShiftEntry::query()
            ->whereNotIn('user_id', $leads)
            ->whereHas('shift', fn($query) => $query->whereBetween('start', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]));
        // A no-show still had a shift that day, so checking in doesn't make it "without a shift"
        $withShift = (clone $onDay)->pluck('user_id');
        $entries = $onDay->with(['shift', 'angelType', 'user.personalData'])->whereNull('freeloaded_by')->get();
        // When each of them scanned the code, keyed by user id; null if an admin checked them in
        $times = Checkin::query()->where('day', $date->toDateString())->get()
            ->mapWithKeys(fn(Checkin $checkin) => [$checkin->user_id => $checkin->created_at]);
        $checkedIn = $times->keys();
        $accounts = TelegramAccount::query()->whereIn('user_id', $entries->pluck('user_id'))->get()->keyBy('user_id');
        $here = Notification::query()
            ->where('kind', Notification::PRESHIFT)
            ->where('answer', Notification::HERE)
            ->whereIn('shift_id', $entries->pluck('shift_id')->unique())
            ->get();
        $half = fn(ShiftEntry $entry) => $entry->shift->start->hour < 12 ? 'AM' : 'PM';
        $isIn = fn(ShiftEntry $entry) => $checkedIn->contains($entry->user_id);

        $count = fn(Collection $entries) => [
            'people'     => $entries->pluck('user_id')->unique()->count(),
            'checked_in' => $entries->filter($isIn)->pluck('user_id')->unique()->count(),
        ];
        $areas = $entries->groupBy('angel_type_id')
            ->map(fn(Collection $entries) => [
                'name'  => $entries->first()->angelType->name,
                'AM'    => $count($entries->filter(fn(ShiftEntry $entry) => $half($entry) === 'AM')),
                'PM'    => $count($entries->filter(fn(ShiftEntry $entry) => $half($entry) === 'PM')),
                'total' => $count($entries),
                'in'    => $entries->filter($isIn)
                    ->groupBy($half)
                    ->map(fn(Collection $entries) => $entries->pluck('user')
                        ->unique('id')
                        // Checked in by hand last, their time isn't known
                        ->sortBy(fn(User $user) => [
                            $times->get($user->id)?->getTimestamp() ?? PHP_INT_MAX,
                            $user->name,
                        ])
                        ->values())
                    ->sortKeys(),
            ])
            ->sortBy('name')
            ->values();

        $missing = $entries->reject($isIn)
            ->groupBy('user_id')
            ->map(fn(Collection $entries) => [
                'user'   => $entries->first()->user,
                'handle' => $accounts->get($entries->first()->user_id)?->username,
                'shifts' => $entries->sortBy('shift.start')
                    ->map(fn(ShiftEntry $entry) => $entry->angelType->name . ' ' . $half($entry))
                    ->unique()
                    ->values(),
                'here'   => $here->contains(fn(Notification $mark) => $mark->user_id === $entries->first()->user_id),
            ])
            ->sortBy(fn(array $person) => [$person['shifts']->first(), $person['user']->name])
            ->values();

        return [
            'days'          => $days->all(),
            'day'           => $date,
            'areas'         => $areas,
            'total'         => [
                'AM'    => $count($entries->filter(fn(ShiftEntry $entry) => $half($entry) === 'AM')),
                'PM'    => $count($entries->filter(fn(ShiftEntry $entry) => $half($entry) === 'PM')),
                'total' => $count($entries),
            ],
            'missing'       => $missing,
            'times'         => $times,
            'without_shift' => $checkedIn->diff($withShift)->diff($leads)->count(),
        ];
    }

    /**
     * Per area: how many volunteers are connected to the bot and have completed onboarding, and
     * who is still missing; people in several areas count in each, areas with only leads are left out
     *
     * @return array{areas: array, total: array{people: int, connected: int, onboarded: int}}
     */
    protected static function onboarding(Collection $leads): array
    {
        $accounts = TelegramAccount::query()->whereNotIn('user_id', $leads)->get()->keyBy('user_id');
        $memberships = UserAngelType::query()->whereNotIn('user_id', $leads)->get();
        $people = User::query()
            ->with(['state', 'personalData'])
            ->whereIn('id', $memberships->pluck('user_id')->merge($accounts->keys())->unique())
            ->orderBy('name')
            ->get()
            ->map(fn(User $user) => [
                'user'      => $user,
                'handle'    => $accounts->get($user->id)?->username,
                'connected' => (bool) $accounts->get($user->id)?->chat_id,
                'onboarded' => $user->state->arrived,
            ])
            ->keyBy(fn(array $person) => $person['user']->id);

        $areas = [];
        $byArea = $memberships->groupBy('angel_type_id');
        foreach (AngelType::query()->orderBy('name')->get() as $area) {
            $members = ($byArea->get($area->id) ?? new Collection())
                ->map(fn(UserAngelType $membership) => $people[$membership->user_id])
                ->sortBy(fn(array $person) => $person['user']->name)
                ->values();
            if ($members->isNotEmpty()) {
                $areas[] = self::summary($area->name, $members);
            }
        }

        $noArea = $people->reject(fn(array $person) => $memberships->contains('user_id', $person['user']->id));
        if ($noArea->isNotEmpty()) {
            $areas[] = self::summary('No area', $noArea->values());
        }

        return ['areas' => $areas, 'total' => self::counts($people)];
    }

    protected static function summary(string $name, Collection $members): array
    {
        return [
            'name'    => $name,
            ...self::counts($members),
            'missing' => $members->reject(fn(array $person) => $person['onboarded'])->values(),
        ];
    }

    /**
     * @return array{people: int, connected: int, onboarded: int}
     */
    protected static function counts(Collection $people): array
    {
        return [
            'people'    => $people->count(),
            'connected' => $people->where('connected', true)->count(),
            'onboarded' => $people->where('onboarded', true)->count(),
        ];
    }
}
