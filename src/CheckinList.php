<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The daily check-in at the desk, per volunteer: who with a shift that day checked in and who
 * didn't; and check-ins by hand: an admin checks people in, or takes a check-in back, for any day
 * with shifts, e.g. for someone whose phone died, and checks in every lead for every day. Shift
 * attendance (no-shows) is Attendance.
 */
class CheckinList
{
    public const MAX_RESULTS = 50;

    /**
     * @param string|null $day    the day whose check-ins are shown, today by default
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
            ...self::day($days->isEmpty() ? null : $date, $now, $leads),
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
     * The day's volunteers with a shift (no-shows on all their shifts aren't expected), each once
     * with all their shifts, split into who checked in at the desk (by time, by hand last) and who
     * didn't; leads aren't listed, they are checked in for every day at once
     *
     * @return array{expected: int, checked_in: Collection, missing: Collection, without_shift: int}
     */
    protected static function day(?Carbon $date, Carbon $now, Collection $leads): array
    {
        if (!$date) {
            return [
                'expected'      => 0,
                'checked_in'    => new Collection(),
                'missing'       => new Collection(),
                'without_shift' => 0,
            ];
        }

        $onShifts = Attendance::rows($date, $now, $leads)->flatMap(fn(array $row) => $row['people']);
        $people = $onShifts
            ->filter(fn(array $person) => !$person['lead'] && !$person['no_show'])
            ->groupBy(fn(array $person) => $person['user']->id)
            ->map(fn(Collection $shifts) => [
                ...$shifts->first(),
                'shifts' => $shifts->pluck('shift')->unique()->values(),
            ]);
        $times = Checkin::query()
            ->where('day', $date->toDateString())
            ->get()
            ->mapWithKeys(fn(Checkin $checkin) => [$checkin->user_id => $checkin->created_at]);
        $people = $people->map(fn(array $person) => [
            ...$person,
            'checked_in' => $times->has($person['user']->id),
            'time'       => $times->get($person['user']->id),
        ]);

        return [
            'expected'      => $people->count(),
            'checked_in'    => $people->where('checked_in', true)
                ->sortBy(fn(array $person) => [
                    $person['time']?->getTimestamp() ?? PHP_INT_MAX,
                    mb_strtolower($person['user']->name),
                ])
                ->values(),
            'missing'       => $people->where('checked_in', false)
                ->sortBy(fn(array $person) => [$person['shifts']->first(), mb_strtolower($person['user']->name)])
                ->values(),
            // Leads and people on a shift that day (no-shows too) aren't "without a shift"
            'without_shift' => $times->keys()
                ->diff($leads)
                ->diff($onShifts->map(fn(array $person) => $person['user']->id))
                ->count(),
        ];
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
