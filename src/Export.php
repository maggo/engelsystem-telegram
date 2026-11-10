<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\AngelType;
use Engelsystem\Models\LogEntry;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Raw records for dashboards, one row per fact, for what Engelsystem's API doesn't show: the
 * daily check-ins, onboarding, Telegram, roll call marks, no-shows and the log. Leads stay in the
 * rows with lead: true, so numbers of volunteers can leave them out; see ApiController
 */
class Export
{
    public const MAX_LOG_ENTRIES = 5000;

    /**
     * One row per shift and area (the areas it needs and those of the people on it): volunteers
     * needed, filled and open as on the dashboard and in /gaps (leads count neither as needed nor
     * as filled, standby has no open slots), and when the roll call went out
     */
    public static function shifts(?string $day): array
    {
        $leads = Roster::leadIds();
        $standby = Roster::standbyArea();
        $shifts = self::shiftsOn($day)->get();
        $rollcalls = Notification::query()
            ->where('kind', Notification::ROLLCALL)
            ->whereIn('shift_id', $shifts->pluck('id'))
            ->get()
            ->keyBy(fn(Notification $row) => $row->shift_id . '|' . $row->angel_type_id);

        $rows = [];
        foreach ($shifts as $shift) {
            $needed = Roster::needed($shift);
            $areas = $needed->pluck('angelType')->concat($shift->shiftEntries->pluck('angelType'))
                ->unique('id')
                ->sortBy('name');
            foreach ($areas as $area) {
                $entries = $shift->shiftEntries->where('angel_type_id', $area->id);
                $count = (int) $needed->where('angel_type_id', $area->id)->sum('count');
                $staffing = Roster::volunteers($count, $entries, $leads);
                $isStandby = $area->id === $standby?->id;
                $volunteers = $entries->reject(fn(ShiftEntry $entry) => $leads->contains($entry->user_id));
                $rows[] = [
                    ...self::shift($shift),
                    'area'               => self::area($area),
                    'standby'            => $isStandby,
                    'needed'             => $staffing['needed'],
                    'filled'             => $staffing['filled'],
                    'open'               => $isStandby ? 0 : max(0, $staffing['needed'] - $staffing['filled']),
                    'no_shows'           => $volunteers->whereNotNull('freeloaded_by')->count(),
                    'leads'              => $entries->filter(fn(ShiftEntry $entry) => $leads->contains($entry->user_id))
                        ->pluck('user_id')
                        ->values(),
                    'engelsystem_needed' => $count,
                    'rollcall_sent_at'   => $rollcalls->get($shift->id . '|' . $area->id)?->sent_at,
                ];
            }
        }

        return $rows;
    }

    /**
     * One row per person on a shift (no-shows included): their check-in that day, the roll call
     * (here = a lead marked them here; replacement = moved onto the shift by the bot and not yet
     * marked here, both are the same answer), the reminder and Engelsystem's no-show
     */
    public static function entries(?string $day): array
    {
        $leads = Roster::leadIds();
        $shifts = self::shiftsOn($day)
            ->with(['shiftEntries.user.personalData', 'shiftEntries.user.state', 'shiftEntries.freeloadedBy'])
            ->get();
        $entries = $shifts->flatMap(fn(Shift $shift) => $shift->shiftEntries
            ->each(fn(ShiftEntry $entry) => $entry->setRelation('shift', $shift)));
        $checkins = self::checkinsOf(
            $entries->pluck('user_id')->unique(),
            $shifts->map(fn(Shift $shift) => $shift->start->toDateString())->unique()
        );
        $answers = Notification::query()
            ->where('kind', Notification::PRESHIFT)
            ->whereIn('shift_id', $shifts->pluck('id'))
            ->get()
            ->keyBy(fn(Notification $row) => $row->shift_id . '|' . $row->user_id);

        return $entries->map(function (ShiftEntry $entry) use ($leads, $checkins, $answers): array {
            $checkin = $checkins->get($entry->user_id . '|' . $entry->shift->start->toDateString());
            $answer = $answers->get($entry->shift_id . '|' . $entry->user_id);

            return [
                'id'               => $entry->id,
                'shift'            => self::shift($entry->shift),
                'area'             => self::area($entry->angelType),
                'user'             => self::user($entry->user),
                'lead'             => $leads->contains($entry->user_id),
                'onboarded'        => $entry->user->state->arrived,
                'checked_in'       => (bool) $checkin,
                'checked_in_at'    => $checkin?->created_at,
                'here'             => $answer?->answer === Notification::HERE,
                'replacement'      => $answer?->answer === Notification::MOVED,
                // For a replacement, when they were told about the shift
                'reminder_sent_at' => $answer?->sent_at,
                'no_show'          => $entry->freeloaded_by !== null,
                'no_show_by'       => $entry->freeloadedBy ? self::user($entry->freeloadedBy) : null,
                'no_show_comment'  => $entry->freeloaded_by !== null ? $entry->freeloaded_comment : null,
                'comment'          => $entry->user_comment ?: null,
            ];
        })->values()->all();
    }

    /**
     * One row per person and day for everyone who checked in or had a shift that day (no-shows
     * included): when they checked in (null and by_hand when an admin checked them in) and
     * their shifts that day
     */
    public static function checkins(?string $day): array
    {
        $leads = Roster::leadIds();
        $days = $day ? collect([$day]) : Checkin::days()->keys()
            ->concat(Checkin::query()->distinct()->pluck('day')->map(fn($value) => substr((string) $value, 0, 10)))
            ->unique()
            ->sort()
            ->values();

        $entries = ShiftEntry::query()
            ->with(['shift', 'angelType'])
            ->whereHas('shift', fn(Builder $query) => self::onDays($query, $days))
            ->get()
            ->groupBy(fn(ShiftEntry $entry) => $entry->user_id . '|' . $entry->shift->start->toDateString());
        $checkins = Checkin::query()
            ->whereIn('day', $days)
            ->get()
            ->keyBy(fn(Checkin $row) => $row->user_id . '|' . substr((string) $row->day, 0, 10));
        $keys = $entries->keys()->concat($checkins->keys())->unique();
        $users = User::query()
            ->with('personalData')
            ->whereIn('id', $keys->map(fn(string $key) => (int) explode('|', $key)[0])->unique())
            ->get()
            ->keyBy('id');

        return $keys
            ->map(function (string $key) use ($entries, $checkins, $users, $leads): array {
                [$userId, $day] = explode('|', $key);
                $checkin = $checkins->get($key);

                return [
                    'day'           => $day,
                    'user'          => self::user($users[(int) $userId]),
                    'lead'          => $leads->contains((int) $userId),
                    'checked_in'    => (bool) $checkin,
                    'checked_in_at' => $checkin?->created_at,
                    'by_hand'       => $checkin && !$checkin->created_at,
                    'shifts'        => ($entries->get($key) ?? collect())
                        ->sortBy('shift.start')
                        ->map(fn(ShiftEntry $entry) => [
                            'id'       => $entry->shift_id,
                            'entry_id' => $entry->id,
                            'area'     => self::area($entry->angelType),
                            'half_day' => self::halfDay($entry->shift),
                            'no_show'  => $entry->freeloaded_by !== null,
                        ])
                        ->values(),
                ];
            })
            ->sortBy(fn(array $row) => [$row['day'], $row['user']['name']])
            ->values()
            ->all();
    }

    /**
     * Everyone in Engelsystem: their areas (lead = supporter of it), onboarding (Engelsystem's
     * arrived) and Telegram
     */
    public static function people(): array
    {
        $accounts = TelegramAccount::query()->get()->keyBy('user_id');

        return User::query()
            ->with(['personalData', 'state', 'userAngelTypes'])
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($accounts): array {
                $account = $accounts->get($user->id);
                $areas = $user->userAngelTypes
                    ->sortBy('name')
                    ->map(fn(AngelType $area) => [...self::area($area), 'lead' => (bool) $area->pivot->supporter])
                    ->values();

                return [
                    ...self::user($user),
                    'areas'              => $areas,
                    'lead'               => $areas->contains('lead', true),
                    'onboarded'          => $user->state->arrived,
                    'onboarded_at'       => $user->state->arrival_date,
                    'telegram_handle'    => $account?->username,
                    'telegram_connected' => (bool) $account?->chat_id,
                ];
            })
            ->all();
    }

    /**
     * Engelsystem's log (Admin > Log) in order, the only record of moves and removals: entries
     * after the id given, up to MAX_LOG_ENTRIES; bot = written by the bot, the user then is
     * whoever's action it was, if anyone's
     *
     * @return array{data: array, next: int|null} next = the id to continue after
     */
    public static function log(int $after, ?Carbon $since): array
    {
        $rows = LogEntry::query()
            ->with('user')
            ->where('id', '>', $after)
            ->when($since, fn(Builder $query) => $query->where('created_at', '>=', $since))
            ->orderBy('id')
            ->limit(self::MAX_LOG_ENTRIES + 1)
            ->get();
        $more = $rows->count() > self::MAX_LOG_ENTRIES;
        $rows = $rows->take(self::MAX_LOG_ENTRIES);

        return [
            'data' => $rows->map(fn(LogEntry $row) => [
                'id'         => $row->id,
                'created_at' => $row->created_at,
                'level'      => $row->level,
                'message'    => $row->message,
                'bot'        => str_starts_with($row->message, Roster::LOG_PREFIX),
                'user'       => $row->user ? self::user($row->user) : null,
            ])->values()->all(),
            'next' => $more ? $rows->last()->id : null,
        ];
    }

    protected static function shiftsOn(?string $day): Builder
    {
        return Shift::query()
            ->with(['schedule', 'location', 'neededAngelTypes.angelType', 'shiftEntries.angelType'])
            ->when($day, fn(Builder $query) => self::onDays($query, collect([$day])))
            ->orderBy('start')
            ->orderBy('id');
    }

    protected static function onDays(Builder $query, Collection $days): Builder
    {
        return $query->where(function (Builder $query) use ($days): void {
            foreach ($days as $day) {
                $date = Carbon::parse($day);
                $query->orWhereBetween('start', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]);
            }
        });
    }

    /**
     * @return Collection<string, Checkin> keyed "user id|day"
     */
    protected static function checkinsOf(Collection $userIds, Collection $days): Collection
    {
        return Checkin::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('day', $days)
            ->get()
            ->keyBy(fn(Checkin $row) => $row->user_id . '|' . substr((string) $row->day, 0, 10));
    }

    /**
     * AM starts before noon, as in the import and on the dashboard
     */
    protected static function halfDay(Shift $shift): string
    {
        return $shift->start->hour < 12 ? 'AM' : 'PM';
    }

    protected static function shift(Shift $shift): array
    {
        return [
            'id'        => $shift->id,
            'name'      => $shift->title,
            'day'       => $shift->start->toDateString(),
            'half_day'  => self::halfDay($shift),
            'starts_at' => $shift->start,
            'ends_at'   => $shift->end,
            'location'  => $shift->location ? ['id' => $shift->location->id, 'name' => $shift->location->name] : null,
            'url'       => url('/shifts', ['action' => 'view', 'shift_id' => $shift->id]),
        ];
    }

    protected static function area(AngelType $area): array
    {
        return ['id' => $area->id, 'name' => $area->name];
    }

    protected static function user(User $user): array
    {
        return [
            'id'         => $user->id,
            'name'       => $user->name,
            'first_name' => $user->personalData?->first_name ?? '',
            'last_name'  => $user->personalData?->last_name ?? '',
        ];
    }
}
