<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Illuminate\Support\Collection;

/**
 * Attendance of a day's shifts: per shift and area how many volunteers are needed, assigned,
 * checked in at the desk that day, marked here in the lead's roll call and marked no-show
 * (Engelsystem's "freeloaded"), with everyone on the shift. Marking no-shows stays where
 * Engelsystem and the roll call do it; the daily check-in at the desk is CheckinList.
 */
class Attendance
{
    /** Lists of people are in this order: confirmed by the lead first, no-shows last */
    public const STATUSES = ['here', 'checked_in', 'missing', 'no_show'];

    /**
     * @param string|null $day the day whose shifts are shown, today by default
     */
    public static function data(?string $day): array
    {
        $days = Checkin::days();
        $now = Carbon::now();
        $date = Checkin::pickDay($days, $day, $now);
        $rows = $days->isEmpty() ? new Collection() : self::rows($date, $now, Roster::leadIds());

        return [
            'now'      => $now,
            'day'      => $date,
            'days'     => $days->all(),
            'shifts'   => $rows,
            'no_shows' => $rows->flatMap(fn(array $row) => $row['people']->where('no_show', true)->where('lead', false))
                ->sortBy(fn(array $person) => [$person['shift'], mb_strtolower($person['user']->name)])
                ->values(),
        ];
    }

    /**
     * Per shift and area of the day: volunteers needed, assigned (no-shows aren't, as in
     * Engelsystem), of those assigned how many checked in at the desk that day and how many a lead
     * marked here in the roll call, and no-shows; leads count in none of them, see
     * Roster::volunteers(), but are listed with everyone else on the shift
     *
     * @return Collection<array{id: string, shift: Shift, needed: int, filled: int, checked_in: int, here: int, no_shows: int, people: Collection}>
     */
    public static function rows(Carbon $date, Carbon $now, Collection $leads): Collection
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
            ->orderBy('title')
            ->get();
        $handles = TelegramAccount::query()
            ->whereIn('user_id', $shifts->flatMap->shiftEntries->pluck('user_id')->unique())
            ->pluck('username', 'user_id');
        $checkedIn = Checkin::query()->where('day', $date->toDateString())->pluck('user_id')->flip();
        // "Is here" in the roll call; a replacement counts once the lead marks them, as in the roll call
        $here = Notification::query()
            ->where('kind', Notification::PRESHIFT)
            ->where('answer', Notification::HERE)
            ->whereIn('shift_id', $shifts->pluck('id'))
            ->get()
            ->mapWithKeys(fn(Notification $mark) => [$mark->shift_id . '|' . $mark->user_id => true]);

        $rows = new Collection();
        foreach ($shifts as $shift) {
            $needed = Roster::needed($shift);
            $areas = $needed->pluck('angelType')->concat($shift->shiftEntries->pluck('angelType'))
                ->unique('id')
                ->sortBy('name');
            foreach ($areas as $area) {
                $all = $shift->shiftEntries->where('angel_type_id', $area->id);
                $label = sprintf('%s–%s %s', $shift->start->format('H:i'), $shift->end->format('H:i'), $area->name);
                $people = $all
                    ->map(fn(ShiftEntry $entry) => [
                        'user'       => $entry->user,
                        'handle'     => $handles->get($entry->user_id),
                        'lead'       => $leads->contains($entry->user_id),
                        'no_show'    => $entry->freeloaded_by !== null,
                        'checked_in' => $checkedIn->has($entry->user_id),
                        'here'       => $here->has($shift->id . '|' . $entry->user_id),
                        'comment'    => (string) $entry->freeloaded_comment,
                        'shift'   => $label,
                    ])
                    ->map(fn(array $person) => [...$person, 'status' => self::status($person)])
                    ->sortBy(fn(array $person) => [
                        !$person['lead'],
                        array_search($person['status'], self::STATUSES, true),
                        mb_strtolower($person['user']->displayName),
                    ])
                    ->values();
                $volunteers = $people->where('lead', false)->where('no_show', false);
                $rows->push([
                    'id'         => $shift->id . '-' . $area->id,
                    'shift'      => $shift,
                    'angel_type' => $area,
                    ...Roster::volunteers((int) $needed->where('angel_type_id', $area->id)->sum('count'), $all, $leads),
                    'checked_in' => $volunteers->where('checked_in', true)->count(),
                    'here'       => $volunteers->where('here', true)->count(),
                    'no_shows'   => $people->where('lead', false)->where('no_show', true)->count(),
                    'people'     => $people,
                    'over'       => $shift->end <= $now,
                ]);
            }
        }

        return $rows;
    }

    /**
     * here (marked by the lead in the roll call), checked_in (at the desk), missing or no_show
     */
    protected static function status(array $person): string
    {
        return match (true) {
            $person['no_show']    => 'no_show',
            $person['here']       => 'here',
            $person['checked_in'] => 'checked_in',
            default               => 'missing',
        };
    }
}
