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
 * who haven't checked in at the desk; and onboarding progress per area (who connected Telegram,
 * which Engelsystem can't show); shifts, goodies and the log are Engelsystem's own pages
 */
class Dashboard
{
    public static function data(): array
    {
        $now = Carbon::now();

        return [
            'now'        => $now,
            ...self::shifts($now),
            'onboarding' => self::onboarding(),
            'log_prefix' => Roster::LOG_PREFIX,
        ];
    }

    /**
     * Per shift and area, from now until the end of tomorrow: open slots (standby isn't counted,
     * as with /gaps) and, for today's shifts, who is on it but hasn't checked in at the desk yet
     * (no-shows aren't listed, they are already marked); only rows with either are kept. Started
     * shifts are urgent, as are those starting within three hours, like Engelsystem's public
     * dashboard. The totals count everyone with a shift starting today, also those that ended.
     *
     * @return array{shifts: Collection, checkins: array{people: int, checked_in: int, without_shift: int}}
     */
    protected static function shifts(Carbon $now): array
    {
        $shifts = Shift::query()
            ->with(['schedule', 'neededAngelTypes.angelType', 'shiftEntries.user.personalData', 'shiftEntries.angelType'])
            ->where('end', '>', $now->copy()->startOfDay())
            ->where('start', '<', $now->copy()->addDay()->endOfDay())
            ->orderBy('start')
            ->get();
        $today = fn(Shift $shift) => $shift->start->isSameDay($now);
        $checkedIn = Checkin::query()->where('day', $now->toDateString())->pluck('user_id');
        $onShift = $shifts->filter($today)->flatMap->shiftEntries->pluck('user_id')->unique();
        $accounts = TelegramAccount::query()->whereIn('user_id', $onShift)->get()->keyBy('user_id');
        $leads = UserAngelType::query()->where('supporter', true)->get();
        $here = Notification::query()
            ->where('kind', Notification::PRESHIFT)
            ->where('answer', Notification::HERE)
            ->whereIn('shift_id', $shifts->pluck('id'))
            ->get();
        $standby = Roster::standbyArea();

        $rows = new Collection();
        foreach ($shifts->filter(fn(Shift $shift) => $shift->end > $now) as $shift) {
            $needed = Roster::needed($shift);
            $areas = $needed->pluck('angelType')->concat($shift->shiftEntries->pluck('angelType'))
                ->unique('id')
                ->sortBy('name');
            foreach ($areas as $area) {
                $entries = $shift->shiftEntries
                    ->where('angel_type_id', $area->id)
                    ->whereNull('freeloaded_by')
                    ->sortBy(fn(ShiftEntry $entry) => $entry->user->name);
                $count = (int) $needed->where('angel_type_id', $area->id)->sum('count');
                $open = $area->id === $standby?->id ? 0 : max(0, $count - $entries->count());
                $missing = $today($shift)
                    ? $entries->reject(fn(ShiftEntry $entry) => $checkedIn->contains($entry->user_id))
                    : new Collection();
                if (!$open && $missing->isEmpty()) {
                    continue;
                }

                $rows->push([
                    'shift'      => $shift,
                    'angel_type' => $area,
                    'needed'     => $count,
                    'filled'     => $entries->count(),
                    'open'       => $open,
                    'today'      => $today($shift),
                    'checked_in' => $entries->count() - $missing->count(),
                    'missing'    => $missing->map(fn(ShiftEntry $entry) => [
                        'user'   => $entry->user,
                        'handle' => $accounts->get($entry->user_id)?->username,
                        'lead'   => $leads->contains(fn(UserAngelType $lead) => $lead->user_id === $entry->user_id
                            && $lead->angel_type_id === $area->id),
                        'here'   => $here->contains(fn(Notification $mark) => $mark->shift_id === $shift->id
                            && $mark->user_id === $entry->user_id),
                    ])->values(),
                    'urgency'    => match (true) {
                        $shift->start <= $now                      => 'danger',
                        $shift->start <= $now->copy()->addHours(3) => 'warning',
                        default                                    => '',
                    },
                ]);
            }
        }

        return ['shifts' => $rows, 'checkins' => [
            'people'        => $onShift->count(),
            'checked_in'    => $onShift->intersect($checkedIn)->count(),
            'without_shift' => $checkedIn->diff($onShift)->count(),
        ]];
    }

    /**
     * Per area: how many people are connected to the bot and have completed onboarding, and who
     * is still missing; people in several areas count in each
     *
     * @return array{areas: array, total: array{people: int, connected: int, onboarded: int}}
     */
    protected static function onboarding(): array
    {
        $accounts = TelegramAccount::query()->get()->keyBy('user_id');
        $memberships = UserAngelType::query()->get();
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
                ->map(fn(UserAngelType $membership) => [...$people[$membership->user_id], 'lead' => $membership->supporter])
                ->sortBy(fn(array $person) => $person['user']->name)
                ->values();
            $areas[] = self::summary($area->name, $members);
        }

        $noArea = $people->reject(fn(array $person) => $memberships->contains('user_id', $person['user']->id));
        if ($noArea->isNotEmpty()) {
            $areas[] = self::summary('No area', $noArea->map(fn(array $person) => [...$person, 'lead' => false])->values());
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
