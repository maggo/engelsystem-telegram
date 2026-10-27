<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\AngelType;
use Engelsystem\Models\User\User;
use Engelsystem\Models\UserAngelType;
use Illuminate\Support\Collection;

/**
 * Onboarding progress per area, the one thing Engelsystem can't show (who connected Telegram);
 * shifts, gaps, goodies and the log are Engelsystem's own pages
 */
class Dashboard
{
    public static function data(): array
    {
        return [
            'now'        => Carbon::now(),
            'onboarding' => self::onboarding(),
            'log_prefix' => Roster::LOG_PREFIX,
        ];
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
