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
 * Open slots today and tomorrow (like /gaps), today's check-ins and onboarding progress per area
 * (who checked in or connected Telegram, which Engelsystem can't show); shifts, goodies and the
 * log are Engelsystem's own pages
 */
class Dashboard
{
    public static function data(): array
    {
        $now = Carbon::now();

        return [
            'now'        => $now,
            'gaps'       => self::gaps($now),
            'checkins'   => self::checkins($now),
            'onboarding' => self::onboarding(),
            'log_prefix' => Roster::LOG_PREFIX,
        ];
    }

    /**
     * Open slots from now until the end of tomorrow, without standby; started shifts are urgent,
     * as are those starting within three hours (Engelsystem's public dashboard does the same)
     *
     * @return Collection<array{shift: Shift, angel_type: AngelType, needed: int, filled: int, urgency: string}>
     */
    protected static function gaps(Carbon $now): Collection
    {
        return Roster::gaps($now, $now->copy()->addDay()->endOfDay())
            ->map(fn(array $gap) => [...$gap, 'urgency' => match (true) {
                $gap['shift']->start <= $now                      => 'danger',
                $gap['shift']->start <= $now->copy()->addHours(3) => 'warning',
                default                                           => '',
            }])
            ->values();
    }

    /**
     * Per area: who has a shift starting today and who of them checked in at the desk today, and
     * who is still missing with their first shift; people with shifts in several areas count in each
     *
     * @return array{areas: array, total: array{people: int, checked_in: int, without_shift: int}}
     */
    protected static function checkins(Carbon $now): array
    {
        $entries = ShiftEntry::query()
            ->with(['shift', 'user.personalData'])
            ->whereHas('shift', fn($q) => $q->whereBetween('start', [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
            ]))
            ->get()
            ->sortBy(fn(ShiftEntry $entry) => $entry->shift->start);
        $checkedIn = Checkin::query()->where('day', $now->toDateString())->pluck('user_id');
        $accounts = TelegramAccount::query()->whereIn('user_id', $entries->pluck('user_id'))->get()->keyBy('user_id');
        $leads = UserAngelType::query()->where('supporter', true)->get();

        $areas = [];
        $byArea = $entries->groupBy('angel_type_id');
        foreach (AngelType::query()->orderBy('name')->get() as $area) {
            if (!$byArea->has($area->id)) {
                continue;
            }
            // First shift today per person, as entries are sorted by start; so is the missing list
            $people = $byArea->get($area->id)->unique('user_id')->map(fn(ShiftEntry $entry) => [
                'user'       => $entry->user,
                'handle'     => $accounts->get($entry->user_id)?->username,
                'lead'       => $leads->contains(fn(UserAngelType $lead) => $lead->user_id === $entry->user_id
                    && $lead->angel_type_id === $area->id),
                'shift'      => $entry->shift,
                'checked_in' => $checkedIn->contains($entry->user_id),
            ]);
            $areas[] = [
                'name'       => $area->name,
                'people'     => $people->count(),
                'checked_in' => $people->where('checked_in', true)->count(),
                'missing'    => $people->reject(fn(array $person) => $person['checked_in'])->values(),
            ];
        }

        $onShift = $entries->pluck('user_id')->unique();
        return ['areas' => $areas, 'total' => [
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
