<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Check-ins per shift of a day: how many checked in, are assigned and are needed, with everyone on
 * the shift; and check-ins by hand: an admin checks people in, or takes a check-in back, for any
 * day with shifts, e.g. for someone whose phone died, and checks in every lead for every day
 */
class CheckinList
{
    public const MAX_RESULTS = 50;

    /**
     * @param string|null $day    the day whose shifts are shown, today by default
     * @param string|null $search part of a name or Telegram handle
     * @param int|null    $userId one person, e.g. from the dashboard's list of who hasn't checked in
     */
    public static function data(?string $day, ?string $search, ?int $userId): array
    {
        $days = Checkin::days();
        $now = Carbon::now();
        $date = Checkin::pickDay($days, $day, $now);
        $leads = Roster::leadIds();
        $search = trim((string) $search);
        $term = ltrim($search, '@');

        $users = new Collection();
        if ($userId || $term !== '') {
            $users = User::query()
                ->with(['personalData', 'userAngelTypes'])
                ->when($userId, fn(Builder $query) => $query->whereKey($userId))
                ->when(!$userId, fn(Builder $query) => self::matching($query, $term))
                ->orderBy('name')
                ->limit(self::MAX_RESULTS + 1)
                ->get();
        }
        $more = $users->count() > self::MAX_RESULTS;
        $users = $users->take(self::MAX_RESULTS);

        $handles = TelegramAccount::query()->whereIn('user_id', $users->pluck('id'))->pluck('username', 'user_id');
        $checkins = Checkin::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->groupBy('user_id')
            ->map(fn(Collection $rows) => $rows->map(fn(Checkin $row) => substr((string) $row->day, 0, 10))->all());

        return [
            'now'    => $now,
            'day'    => $date,
            ...self::shifts($days->isEmpty() ? null : $date, $now, $leads),
            'days'   => $days->all(),
            'search' => $userId ? '' : $search,
            'more'   => $more,
            'people' => $users->map(fn(User $user) => [
                'user'   => $user,
                'handle' => $handles->get($user->id),
                'lead'   => $leads->contains($user->id),
                'areas'  => $user->userAngelTypes->pluck('name')->sort()->values(),
                'days'   => $checkins->get($user->id, []),
            ])->values(),
            'leads'  => [
                'people'  => $leads->count(),
                'missing' => $leads->count() * $days->count()
                    - Checkin::query()->whereIn('user_id', $leads)->whereIn('day', $days->keys())->count(),
            ],
        ];
    }

    /**
     * Per shift and area of the day: volunteers checked in at the desk that day, assigned (no-shows
     * aren't, as in Engelsystem), needed and no-shows; leads count in none of them, see
     * Roster::volunteers(), but are listed with everyone else on the shift. And the day's volunteers
     * on a shift who haven't checked in (once, with all their shifts), and its no-shows, leads too.
     *
     * @return array{shifts: Collection, missing: Collection, no_shows: Collection}
     */
    protected static function shifts(?Carbon $date, Carbon $now, Collection $leads): array
    {
        if (!$date) {
            return ['shifts' => new Collection(), 'missing' => new Collection(), 'no_shows' => new Collection()];
        }

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
        $userIds = $shifts->flatMap->shiftEntries->pluck('user_id')->unique();
        $times = Checkin::query()
            ->where('day', $date->toDateString())
            ->whereIn('user_id', $userIds)
            ->get()
            ->mapWithKeys(fn(Checkin $checkin) => [$checkin->user_id => $checkin->created_at]);
        $handles = TelegramAccount::query()->whereIn('user_id', $userIds)->pluck('username', 'user_id');

        $rows = new Collection();
        foreach ($shifts as $shift) {
            $needed = Roster::needed($shift);
            $areas = $needed->pluck('angelType')->concat($shift->shiftEntries->pluck('angelType'))
                ->unique('id')
                ->sortBy('name');
            foreach ($areas as $area) {
                $all = $shift->shiftEntries->where('angel_type_id', $area->id);
                $staffing = Roster::volunteers(
                    (int) $needed->where('angel_type_id', $area->id)->sum('count'),
                    $all,
                    $leads
                );
                $id = $shift->id . '-' . $area->id;
                $label = sprintf('%s–%s %s', $shift->start->format('H:i'), $shift->end->format('H:i'), $area->name);
                $people = $all
                    ->map(fn(ShiftEntry $entry) => [
                        'user'       => $entry->user,
                        'handle'     => $handles->get($entry->user_id),
                        'lead'       => $leads->contains($entry->user_id),
                        'no_show'    => $entry->freeloaded_by !== null,
                        'comment'    => (string) $entry->freeloaded_comment,
                        'checked_in' => $times->has($entry->user_id),
                        'time'       => $times->get($entry->user_id),
                        'row'        => $id,
                        'shift'      => $label,
                    ])
                    // Leads first, then by name: the lists don't move while people are checked in
                    ->sortBy(fn(array $person) => [!$person['lead'], mb_strtolower($person['user']->name)])
                    ->values();
                $volunteers = $people->where('lead', false)->where('no_show', false);
                $rows->push([
                    'id'         => $id,
                    'shift'      => $shift,
                    'angel_type' => $area,
                    ...$staffing,
                    'checked_in' => $volunteers->where('checked_in', true)->count(),
                    'missing'    => $volunteers->where('checked_in', false)->count(),
                    'no_shows'   => $people->where('lead', false)->where('no_show', true)->count(),
                    'groups'     => array_filter([
                        'Not checked in' => $people->where('no_show', false)->where('checked_in', false)->values(),
                        'Checked in'     => $people->where('no_show', false)->where('checked_in', true)->values(),
                        'No-shows'       => $people->where('no_show', true)->values(),
                    ], fn(Collection $group) => $group->isNotEmpty()),
                    'over'       => $shift->end <= $now,
                ]);
            }
        }

        $everyone = $rows->flatMap(fn(array $row) => collect($row['groups'])->flatten(1));
        $missing = $everyone
            ->filter(fn(array $person) => !$person['lead'] && !$person['no_show'] && !$person['checked_in'])
            ->groupBy(fn(array $person) => $person['user']->id)
            ->map(fn(Collection $shifts) => [
                ...$shifts->first(),
                'shifts' => $shifts->pluck('shift')->unique()->values(),
            ])
            ->sortBy(fn(array $person) => [$person['shifts']->first(), mb_strtolower($person['user']->name)])
            ->values();
        $noShows = $everyone
            ->where('no_show', true)
            ->sortBy(fn(array $person) => [$person['shift'], mb_strtolower($person['user']->name)])
            ->values();

        return ['shifts' => $rows, 'missing' => $missing, 'no_shows' => $noShows];
    }

    /**
     * Username, first or last name, or Telegram handle containing the term
     */
    protected static function matching(Builder $query, string $term): Builder
    {
        $like = '%' . addcslashes(mb_strtolower($term), '%_\\') . '%';

        return $query->where(fn(Builder $query) => $query
            ->whereRaw('LOWER(name) LIKE ?', [$like])
            ->orWhereHas('personalData', fn(Builder $query) => $query
                ->whereRaw('LOWER(first_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                ->orWhereRaw(
                    "LOWER(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) LIKE ?",
                    [$like]
                ))
            ->orWhereIn(
                'id',
                TelegramAccount::query()->whereRaw('LOWER(username) LIKE ?', [$like])->select('user_id')
            ));
    }
}
