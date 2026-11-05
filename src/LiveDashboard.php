<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Illuminate\Support\Collection;

/**
 * The coordinators' screen at the desk: a day's volunteers, who checked in and who is missing in
 * the shift that runs now or comes next, and every shift and area of that day with checked in,
 * assigned and needed. The page fetches itself to pop up new check-ins and celebrate shifts that
 * are complete, see live.js. Leads (supporters of any area) aren't counted or listed
 * anywhere, only volunteers are.
 */
class LiveDashboard
{
    /**
     * @param string|null $day the day whose shifts are shown, today by default
     */
    public static function data(?string $day = null): array
    {
        $now = Carbon::now();
        $leads = Roster::leadIds();
        $days = Checkin::days();
        $date = Checkin::pickDay($days, $day, $now);
        $board = self::board($date, $now, $leads);

        return [
            'now'        => $now,
            'day'        => $date,
            'days'       => $days->all(),
            ...$board,
            // Pop-ups are about today, whichever day is shown
            'live'       => self::live($now, $leads, $date->isSameDay($now) ? $board['rows'] : self::board($now, $now, $leads)['rows']),
        ];
    }

    /**
     * Every shift and area of the day with its volunteers: needed and assigned as in /gaps (see
     * Roster::volunteers(), standby has no open slots), and how many of them checked in at the
     * desk that day (no-shows aren't counted, they are already marked). A shift is complete once
     * its needs are covered and everyone on it checked in. AM starts before noon, as in the import.
     *
     * @return array{rows: Collection, halves: Collection, tiles: array, missing: Collection|null}
     */
    protected static function board(Carbon $date, Carbon $now, Collection $leads): array
    {
        $shifts = Shift::query()
            ->with([
                'schedule',
                'neededAngelTypes.angelType',
                'shiftEntries.angelType',
                'shiftEntries.user.personalData',
            ])
            ->whereBetween('start', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->orderBy('start')
            ->get();
        $checkedIn = Checkin::query()->where('day', $date->toDateString())->pluck('user_id')->flip();
        $handles = TelegramAccount::query()
            ->whereIn('user_id', $shifts->flatMap->shiftEntries->pluck('user_id')->unique())
            ->pluck('username', 'user_id');
        $here = Notification::query()
            ->where('kind', Notification::PRESHIFT)
            ->where('answer', Notification::HERE)
            ->whereIn('shift_id', $shifts->pluck('id'))
            ->get()
            ->map(fn(Notification $mark) => $mark->shift_id . '|' . $mark->user_id)
            ->flip();
        $standby = Roster::standbyArea();
        // Nobody checks in for a day still to come
        $upcoming = $date->isAfter($now->copy()->endOfDay());

        $rows = new Collection();
        foreach ($shifts as $shift) {
            $needed = Roster::needed($shift);
            $areas = $needed->pluck('angelType')->concat($shift->shiftEntries->pluck('angelType'))
                ->unique('id')
                ->sortBy('name');
            foreach ($areas as $area) {
                $all = $shift->shiftEntries->where('angel_type_id', $area->id);
                $staffing = Roster::volunteers((int) $needed->where('angel_type_id', $area->id)->sum('count'), $all, $leads);
                if (!$staffing['needed'] && $all->isEmpty()) {
                    continue;
                }
                $volunteers = $all->whereNull('freeloaded_by')
                    ->reject(fn(ShiftEntry $entry) => $leads->contains($entry->user_id));
                $missing = $volunteers->reject(fn(ShiftEntry $entry) => $checkedIn->has($entry->user_id))
                    ->map(fn(ShiftEntry $entry) => [
                        'user'   => $entry->user,
                        'handle' => $handles->get($entry->user_id),
                        'here'   => $here->has($shift->id . '|' . $entry->user_id),
                    ])
                    // Marked here in the roll call first, as everywhere
                    ->sortBy(fn(array $person) => [!$person['here'], mb_strtolower($person['user']->displayName)])
                    ->values();
                $open = $area->id === $standby?->id ? 0 : max(0, $staffing['needed'] - $staffing['filled']);

                $rows->push([
                    'key'        => $shift->id . '-' . $area->id,
                    'shift'      => $shift,
                    'angel_type' => $area,
                    'half'       => $shift->start->hour < 12 ? 'AM' : 'PM',
                    ...$staffing,
                    'open'       => $open,
                    'checked_in' => $volunteers->count() - $missing->count(),
                    'missing'    => $missing,
                    'users'      => $volunteers->pluck('user_id')->values(),
                    'standby'    => $area->id === $standby?->id,
                    'over'       => $shift->end <= $now,
                    'started'    => $shift->start <= $now,
                    'status'     => match (true) {
                        $open > 0                                         => 'open',
                        $upcoming || $volunteers->isEmpty()               => 'covered',
                        // Standby has no need to cover: no Complete badge, no confetti
                        $missing->isEmpty() && $area->id === $standby?->id => 'covered',
                        $missing->isEmpty()                               => 'complete',
                        default                                           => 'waiting',
                    },
                ]);
            }
        }

        $halves = $rows->groupBy('half')
            ->sortKeys()
            ->map(fn(Collection $rows, string $half) => [
                'name' => $half,
                // The time most of its shifts have, others show their own
                'time' => $rows->countBy(fn(array $row) => self::time($row['shift']))->sortDesc()->keys()->first(),
                'rows' => $rows->sortBy(fn(array $row) => [mb_strtolower($row['angel_type']->name), $row['shift']->start])
                    ->values(),
                ...self::people($rows, $checkedIn),
            ]);

        return [
            'rows'    => $rows,
            'halves'  => $halves,
            'tiles'   => [
                'checked_in' => ['total' => self::people($rows, $checkedIn)['checked_in']]
                    + $halves->map(fn(array $half) => $half['checked_in'])->all(),
                'people'     => ['total' => self::people($rows, $checkedIn)['people']]
                    + $halves->map(fn(array $half) => $half['people'])->all(),
                'missing'    => self::missingTile($date, $now, $rows, $checkedIn, $upcoming),
            ],
            'missing' => $upcoming ? null : self::missingPeople($rows),
        ];
    }

    /**
     * @return array{people: int, checked_in: int} volunteers on the rows, each once
     */
    protected static function people(Collection $rows, Collection $checkedIn): array
    {
        $users = $rows->flatMap(fn(array $row) => $row['users'])->unique();

        return [
            'people'     => $users->count(),
            'checked_in' => $users->filter(fn($id) => $checkedIn->has($id))->count(),
        ];
    }

    /**
     * Today: who isn't checked in for the half day that runs now (the later one at the handover)
     * or comes next. Another day: who wasn't checked in all day, or for a day to come, the open slots.
     *
     * @return array{label: string, value: int|null, detail: string}
     */
    protected static function missingTile(
        Carbon $date,
        Carbon $now,
        Collection $rows,
        Collection $checkedIn,
        bool $upcoming
    ): array {
        if ($upcoming) {
            return [
                'label'  => 'Open slots',
                'value'  => $rows->sum('open'),
                'detail' => 'volunteers still needed that day',
            ];
        }
        if (!$date->isSameDay($now)) {
            $people = self::people($rows, $checkedIn);

            return [
                'label'  => 'Not checked in',
                'value'  => $people['people'] - $people['checked_in'],
                'detail' => sprintf('of %d volunteers that day', $people['people']),
            ];
        }

        $pending = $rows->reject(fn(array $row) => $row['over']);
        $started = $pending->where('started', true);
        $focus = $started->isNotEmpty()
            ? $started->sortByDesc(fn(array $row) => $row['shift']->start)->first()
            : $pending->sortBy(fn(array $row) => $row['shift']->start)->first();
        if (!$focus) {
            return ['label' => 'Missing', 'value' => null, 'detail' => 'no more shifts today'];
        }

        $people = self::people($pending->where('half', $focus['half']), $checkedIn);

        return [
            'label'  => sprintf('Missing · %s %s shift', $started->isNotEmpty() ? 'this' : 'next', $focus['half']),
            'value'  => $people['people'] - $people['checked_in'],
            'detail' => sprintf('of %d on the %s shift, not checked in', $people['people'], self::time($focus['shift'])),
        ];
    }

    /**
     * Everyone on a shift that day who didn't check in, each once with all their shifts
     */
    protected static function missingPeople(Collection $rows): Collection
    {
        return $rows->flatMap(fn(array $row) => $row['missing']->map(fn(array $person) => [
            ...$person,
            'shift' => $row['angel_type']->name . ' ' . $row['half'],
        ]))
            ->groupBy(fn(array $person) => $person['user']->id)
            ->map(fn(Collection $shifts) => [
                ...$shifts->first(),
                'here'   => $shifts->contains('here', true),
                'shifts' => $shifts->pluck('shift')->unique()->values(),
            ])
            ->sortBy(fn(array $person) => [$person['shifts']->first(), mb_strtolower($person['user']->displayName)])
            ->values();
    }

    /**
     * Today's check-ins and complete shifts, for the page to pop up what's new since it last looked
     *
     * @return array{latest: int, checkins: array, complete: array}
     */
    protected static function live(Carbon $now, Collection $leads, Collection $rows): array
    {
        $checkins = Checkin::query()
            ->with('user.personalData')
            ->where('day', $now->toDateString())
            ->whereNotIn('user_id', $leads)
            ->orderByDesc('id')
            ->limit(20)
            ->get();
        $shifts = $rows->flatMap(fn(array $row) => $row['users']->map(fn($id) => [$id, $row['angel_type']->name . ' ' . $row['half']]))
            ->groupBy(0)
            ->map(fn(Collection $pairs) => $pairs->pluck(1)->unique()->implode(', '));

        return [
            'latest'   => (int) ($checkins->first()?->id ?? 0),
            'checkins' => $checkins->reverse()->map(fn(Checkin $checkin) => [
                'id'      => $checkin->id,
                'name'    => $checkin->user->displayName,
                'shift'   => $shifts->get($checkin->user_id, 'no shift today'),
                'by_hand' => !$checkin->created_at,
            ])->values()->all(),
            'complete' => $rows->where('status', 'complete')
                ->map(fn(array $row) => [
                    'key'  => $row['key'],
                    'name' => $row['angel_type']->name . ' ' . $row['half'],
                ])
                ->values()
                ->all(),
        ];
    }

    protected static function time(Shift $shift): string
    {
        return $shift->start->format('H:i') . '–' . $shift->end->format('H:i');
    }
}
