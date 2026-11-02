<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\AngelType;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;
use Engelsystem\Models\UserAngelType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Swaps people between two shifts of the same half day, e.g. Tuesday's Registration AM and
 * Standby AM, which in Engelsystem means removing and adding each of them by hand
 *
 * A side is a shift and one of its areas. People join the area they move to, as with a
 * replacement, and keep the areas they had. AM starts before noon, as in the import.
 *
 * The other side can also be the free volunteers: anyone in an area who isn't on a shift during
 * the picked one. Moving them adds them to it, moving someone there takes them off it.
 */
class Swap
{
    public const FREE = 'free';

    public const FREE_NAME = 'Free volunteers';

    public function __construct(protected BotApi $api, protected Format $format)
    {
    }

    /**
     * The days with shifts to come, the half day's sides and who is on the two picked ones; a
     * picked side that isn't in this half day stays on its area
     */
    public static function data(?string $day, ?string $half, ?string $left, ?string $right): array
    {
        $now = Carbon::now();
        $days = Shift::query()
            ->where('end', '>', $now)
            ->selectRaw('DATE(start) AS day')
            ->distinct()
            ->orderBy('day')
            ->pluck('day')
            ->mapWithKeys(fn(string $value) => [$value => Carbon::parse($value)->format('D M j')]);
        if (!$days->has($day)) {
            $day = $days->has($now->toDateString()) ? $now->toDateString() : $days->keys()->first();
        }
        $half = in_array($half, ['AM', 'PM']) ? $half : ($now->hour < 12 ? 'AM' : 'PM');

        $sides = $day ? self::sides(Carbon::parse($day), $half) : new Collection();
        $standby = Roster::standbyArea()?->id;
        $left = self::pick($sides, $left)
            ?? $sides->first(fn(array $side) => $side['area']->id !== $standby)['key'] ?? null;
        $right = ($right === self::FREE ? $right : self::pick($sides, $right))
            ?? $sides->first(fn(array $side) => $side['area']->id === $standby && $side['key'] !== $left)['key']
            ?? $sides->first(fn(array $side) => $side['key'] !== $left)['key'] ?? null;

        return [
            'days'      => $days->all(),
            'day'       => $day,
            'half'      => $half,
            'sides'     => $sides->mapWithKeys(fn(array $side) => [$side['key'] => $side['label']])->all(),
            'left'      => $left,
            'right'     => $right,
            'free'      => self::FREE,
            'free_name' => self::FREE_NAME,
            'picked'    => $left && $right && $left !== $right ? [
                'left'  => self::people($sides[$left], Carbon::parse($day)->isToday()),
                'right' => $right === self::FREE
                    ? self::free($sides[$left], Carbon::parse($day)->isToday())
                    : self::people($sides[$right], Carbon::parse($day)->isToday()),
            ] : null,
        ];
    }

    /**
     * Moves the picked entries of each side to the other one, all or nothing
     *
     * @param int[] $fromLeft  shift entry ids
     * @param int[] $fromRight shift entry ids, or user ids for the free volunteers
     * @return array{moves: array, sides: array<array{Shift, AngelType}|null>} for tell(), null for
     *     the free volunteers
     * @throws InvalidArgumentException when nothing moved, with why
     */
    public function run(string $left, string $right, array $fromLeft, array $fromRight, User $by): array
    {
        [$leftShift, $leftArea] = self::side($left);
        $free = $right === self::FREE;
        [$rightShift, $rightArea] = $free ? [null, null] : self::side($right);
        if ($left === $right) {
            throw new InvalidArgumentException('Pick two different shifts.');
        }
        if (!$free && !self::sameHalf($leftShift, $rightShift)) {
            throw new InvalidArgumentException('Both shifts must be in the same half day.');
        }
        $fromLeft = self::ids($fromLeft);
        $fromRight = self::ids($fromRight);
        if (!$fromLeft && !$fromRight) {
            throw new InvalidArgumentException('Tick the people to move first.');
        }

        $moves = app('db.connection')->transaction(function () use (
            $leftShift,
            $leftArea,
            $rightShift,
            $rightArea,
            $fromLeft,
            $fromRight,
            $free,
            $by
        ): array {
            // Always the shifts first, then the people, so two changes never wait on each other
            $shifts = array_filter([$leftShift, $rightShift]);
            Shift::query()->whereKey(array_map(fn(Shift $shift) => $shift->id, $shifts))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($shifts as $shift) {
                if ($shift->end <= Carbon::now()) {
                    throw new InvalidArgumentException(sprintf('%s is over.', $shift->title));
                }
            }

            $offLeft = self::picked($fromLeft, $leftShift, $leftArea);
            $offRight = $free ? null : self::picked($fromRight, $rightShift, $rightArea);
            User::query()
                ->whereKey([...$offLeft->pluck('user_id'), ...($offRight?->pluck('user_id') ?? $fromRight)])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // [user, entry they leave or null, shift they go to or null, area]
            $out = $offLeft->map(fn(ShiftEntry $entry) => [$entry->user, $entry, $rightShift, $rightArea])->all();
            if ($free) {
                $joining = self::freeDuring($leftShift)->whereKey($fromRight)->get();
                if ($joining->count() !== count(array_unique($fromRight))) {
                    throw new InvalidArgumentException(
                        'Someone you ticked under ' . self::FREE_NAME . ' is on a shift by now, nothing was '
                        . 'moved. Check and tick again.'
                    );
                }
                $out = [...$out, ...$joining->map(fn(User $user) => [$user, null, $leftShift, $leftArea])];
            } else {
                $out = [
                    ...$out,
                    ...$offRight->map(fn(ShiftEntry $entry) => [$entry->user, $entry, $leftShift, $leftArea]),
                ];
            }

            // Engelsystem has one entry per person and shift, and nobody moves onto a shift at the
            // same time as another one of theirs; what already overlapped the shift they leave
            // stays fine (AM and PM meet at the handover)
            $picked = array_filter(array_map(fn(array $move) => $move[1]?->id, $out));
            foreach ($out as [$user, $entry, $to]) {
                if (!$to) {
                    continue;
                }
                if ($to->shiftEntries()->where('user_id', $user->id)->whereNotIn('id', $picked)->exists()) {
                    throw new InvalidArgumentException(sprintf('%s is on %s already.', $user->displayName, $to->title));
                }
                $clash = ShiftEntry::query()
                    ->with('shift')
                    ->where('user_id', $user->id)
                    ->whereNotIn('id', $picked)
                    ->whereNull('freeloaded_by')
                    ->whereHas('shift', fn($q) => self::during($q, $to))
                    ->when($entry, fn($q) => $q->whereDoesntHave('shift', fn($q) => self::during($q, $entry->shift)))
                    ->first();
                if ($clash) {
                    throw new InvalidArgumentException(sprintf(
                        '%s is on %s at the same time as %s, nothing was moved.',
                        $user->displayName,
                        $clash->shift->title,
                        $to->title
                    ));
                }
            }

            $moves = [];
            foreach ($out as [$user, $entry, $to, $area]) {
                $from = $entry?->shift;
                $answer = $from ? Notification::query()
                    ->where('kind', Notification::PRESHIFT)
                    ->where('shift_id', $from->id)
                    ->where('user_id', $user->id)
                    ->value('answer') : null;
                if ($entry) {
                    Roster::unassign($entry, $to ? sprintf(
                        'moved to %s (%s) by %s',
                        $to->title,
                        $to->start->format('Y-m-d H:i'),
                        $by->name
                    ) : sprintf('moved to the free volunteers by %s', $by->name));
                }
                $moves[] = ['user' => $user, 'from' => $from, 'to' => $to, 'area' => $area, 'answer' => $answer];
            }
            foreach ($moves as $i => $move) {
                if (!$move['to']) {
                    continue;
                }
                Roster::join($move['user'], $move['area']->id);
                $entry = Roster::assign($move['user'], $move['to'], $move['area']->id, $by->name);
                $moves[$i]['entry'] = $entry;
                // Seen by a lead in this half day stays seen; otherwise, as for a replacement, they
                // aren't asked by the reminder any more and the lead sees them in the roll call
                $soon = $move['to']->start <= Carbon::now()->addMinutes(Scheduler::PRESHIFT_MINUTES);
                if ($move['to']->id !== $move['from']?->id && $soon) {
                    $seen = $move['answer'] === Notification::HERE;
                    Notification::answer($entry, $seen ? Notification::HERE : Notification::MOVED, told: true);
                }
            }

            return $moves;
        });

        return ['moves' => $moves, 'sides' => [[$leftShift, $leftArea], $free ? null : [$rightShift, $rightArea]]];
    }

    /**
     * What moved, for the page: "Asha to Standby AM and Ravi to Registration AM"
     */
    public static function summary(array $result): string
    {
        return collect($result['moves'])
            ->groupBy(fn(array $move) => $move['to'] ? self::name($move['to'], $move['area']) : self::FREE_NAME)
            ->map(fn(Collection $moves, string $to) => $moves
                ->map(fn(array $move) => $move['user']->displayName)
                ->implode(', ') . ' to ' . $to)
            ->implode(' and ');
    }

    /**
     * @return User[] who moved and isn't on Telegram, to be told directly
     */
    public static function unreachable(array $result): array
    {
        return array_values(array_filter(
            array_map(fn(array $move) => $move['user'], $result['moves']),
            fn(User $user) => !TelegramAccount::chatOf($user)
        ));
    }

    /**
     * Each moved person gets their new shift. One summary goes, as for a replacement, to each area's
     * leads group (or the shift's leads directly if the area has none) and to coordination, with a
     * pointer to /rollcall for a shift whose roll call is out already
     */
    public function tell(array $result, User $by): void
    {
        ['moves' => $moves, 'sides' => $sides] = $result;
        foreach ($moves as $move) {
            if ($chat = TelegramAccount::chatOf($move['user'])) {
                $this->api->send($chat, match (true) {
                    !$move['to'] => sprintf(
                        "🔁 %s took you off <b>%s</b> (%s), you don't need to go there. Thank you!",
                        $this->format->user($by),
                        e($move['from']->title),
                        $this->format->shiftTime($move['from'])
                    ),
                    !$move['from'] => sprintf(
                        "🙋 %s added you to this shift:\n\n%s\n\nPlease head there now / on time. Thank you!",
                        $this->format->user($by),
                        $this->format->entry($move['entry'], withLeads: true)
                    ),
                    default => sprintf(
                        "🔁 %s moved you from <b>%s</b> to this shift:\n\n%s\n\n"
                        . 'Please head there now / on time. Thank you!',
                        $this->format->user($by),
                        e($move['from']->title),
                        $this->format->entry($move['entry'], withLeads: true)
                    ),
                });
            }
        }

        $lines = [sprintf(
            '🔁 %s swapped people between <b>%s</b> and <b>%s</b> (%s):',
            $this->format->user($by),
            e(self::name(...$sides[0])),
            e($sides[1] ? self::name(...$sides[1]) : self::FREE_NAME),
            $sides[0][0]->start->format('D M j')
        )];
        $chats = Chat::forArea(null)->pluck('chat_id');
        $moved = collect($moves)->map(fn(array $move) => $move['user']->id)->push($by->id);
        foreach ($sides as $side) {
            [$shift, $area] = $side ?? [null, null];
            $in = collect($moves)
                ->filter(fn(array $move) => $move['to']?->id === $shift?->id && $move['area']?->id === $area?->id);
            $lines[] = '';
            if (!$shift) {
                $lines[] = '<b>' . self::FREE_NAME . '</b>';
                $lines[] = $in->isEmpty()
                    ? 'Nobody new.'
                    : '+ ' . $in->map(fn(array $move) => $this->format->user($move['user']))->implode(', ');
                continue;
            }
            $staffing = Roster::staffing($shift, $area->id);
            $lines[] = sprintf(
                '<b>%s</b> now %d/%d',
                e(self::name($shift, $area)),
                $staffing['filled'],
                $staffing['needed']
            );
            $lines[] = $in->isEmpty()
                ? 'Nobody new.'
                : '+ ' . $in->map(fn(array $move) => $this->format->user($move['user']))->implode(', ');
            // The roll call already out is a snapshot: it still lists who left and misses who came
            $touched = collect($moves)
                ->contains(fn(array $move) => in_array($shift->id, [$move['from']?->id, $move['to']?->id]));
            if ($touched && Scheduler::rollCallStarted($shift)) {
                $lines[] = 'Its roll call is out of date, /rollcall shows who is on it now.';
            }
            $groups = Chat::forArea($area->id)->pluck('chat_id');
            $chats = $chats->merge($groups->isNotEmpty() ? $groups : Roster::leadsOf($shift, $area->id)
                ->reject(fn(User $lead) => $moved->contains($lead->id))
                ->map(fn(User $lead) => TelegramAccount::chatOf($lead)));
        }

        foreach ($chats->filter()->map(fn($chat) => (string) $chat)->unique() as $chat) {
            $this->api->send($chat, implode("\n", $lines));
        }
    }

    /**
     * Every shift and area of the half day, by area
     *
     * @return Collection<string, array{key: string, label: string, shift: Shift, area: AngelType}>
     */
    protected static function sides(Carbon $day, string $half): Collection
    {
        $areas = AngelType::query()->get()->keyBy('id');

        return Shift::query()
            ->with(['schedule', 'neededAngelTypes', 'shiftEntries'])
            ->whereBetween('start', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('start')
            ->get()
            ->filter(fn(Shift $shift) => ($shift->start->hour < 12) === ($half === 'AM'))
            ->flatMap(fn(Shift $shift) => Roster::needed($shift)->pluck('angel_type_id')
                ->merge($shift->shiftEntries->pluck('angel_type_id'))
                ->map(fn($id) => (int) $id)
                ->unique()
                ->filter(fn(int $id) => $areas->has($id))
                ->map(fn(int $id) => [
                    'key'   => $shift->id . '-' . $id,
                    'label' => self::label($shift, $areas[$id]),
                    'shift' => $shift,
                    'area'  => $areas[$id],
                ]))
            ->sortBy(fn(array $side) => [$side['area']->name, $side['shift']->start])
            ->keyBy('key');
    }

    /**
     * The picked side, or the same area's side in this half day
     */
    protected static function pick(Collection $sides, ?string $key): ?string
    {
        if ($key === null || $sides->has($key)) {
            return $key;
        }
        $areaId = (int) explode('-', $key . '-')[1];

        return $sides->first(fn(array $side) => $side['area']->id === $areaId)['key'] ?? null;
    }

    /**
     * @return array{Shift, AngelType}
     */
    protected static function side(string $key): array
    {
        [$shiftId, $areaId] = array_map('intval', explode('-', $key . '-'));
        $shift = Shift::query()->find($shiftId);
        $area = AngelType::query()->find($areaId);
        // Only the sides the page offers, see sides(): an area the shift needs or someone is on
        $offered = $shift && $area && Roster::needed($shift)->pluck('angel_type_id')
            ->merge($shift->shiftEntries()->pluck('angel_type_id'))
            ->contains(fn($id) => (int) $id === $area->id);
        if (!$offered) {
            throw new InvalidArgumentException('That shift doesn\'t exist any more, pick it again.');
        }

        return [$shift, $area];
    }

    /**
     * The side's people: leads first, then by name, no-shows last (they stay where they are)
     */
    protected static function people(array $side, bool $today): array
    {
        /** @var Shift $shift */
        $shift = $side['shift'];
        $entries = $shift->shiftEntries()
            ->with(['user.personalData', 'user.state'])
            ->where('angel_type_id', $side['area']->id)
            ->get();
        $accounts = TelegramAccount::query()->whereIn('user_id', $entries->pluck('user_id'))->get()->keyBy('user_id');
        $leadIds = UserAngelType::query()
            ->where('angel_type_id', $side['area']->id)
            ->where('supporter', true)
            ->pluck('user_id')
            ->all();
        $checkedIn = $today ? Checkin::usersOn($shift->start, $entries->pluck('user_id')) : [];
        $staffing = Roster::staffing($shift, $side['area']->id);

        return [
            'name'   => self::name($shift, $side['area']),
            'time'   => $shift->start->format('H:i') . '–' . $shift->end->format('H:i'),
            'over'   => $shift->end <= Carbon::now(),
            'filled' => $staffing['filled'],
            'needed' => $staffing['needed'],
            'people' => $entries
                ->map(fn(ShiftEntry $entry) => [
                    'id'         => $entry->id,
                    'name'       => $entry->user->displayName,
                    'handle'     => $accounts->get($entry->user_id)?->username,
                    'telegram'   => (bool) $accounts->get($entry->user_id)?->chat_id,
                    'lead'       => in_array($entry->user_id, $leadIds),
                    'onboarded'  => $entry->user->state->arrived,
                    'checked_in' => in_array($entry->user_id, $checkedIn),
                    'no_show'    => (bool) $entry->freeloaded_by,
                ])
                ->sortBy(fn(array $person) => [$person['no_show'], !$person['lead'], mb_strtolower($person['name'])])
                ->values()
                ->all(),
        ];
    }

    /**
     * The free volunteers next to the side's shift, checked-in and onboarded people first; `id` is
     * the user's, as they have no entry to move
     */
    protected static function free(array $side, bool $today): array
    {
        /** @var Shift $shift */
        $shift = $side['shift'];
        $users = self::freeDuring($shift)->with(['personalData', 'state'])->get();
        $accounts = TelegramAccount::query()->whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');
        $checkedIn = $today ? Checkin::usersOn($shift->start, $users->pluck('id')) : [];

        return [
            'name'   => self::FREE_NAME,
            'time'   => 'not on a shift ' . $shift->start->format('H:i') . '–' . $shift->end->format('H:i'),
            'over'   => false,
            'filled' => $users->count(),
            'needed' => null,
            'people' => $users
                ->map(fn(User $user) => [
                    'id'         => $user->id,
                    'name'       => $user->displayName,
                    'handle'     => $accounts->get($user->id)?->username,
                    'telegram'   => (bool) $accounts->get($user->id)?->chat_id,
                    'lead'       => false,
                    'onboarded'  => $user->state->arrived,
                    'checked_in' => in_array($user->id, $checkedIn),
                    'no_show'    => false,
                ])
                ->sortBy(fn(array $person) => [
                    !$person['checked_in'],
                    !$person['onboarded'],
                    mb_strtolower($person['name']),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Volunteers (members of an area) who aren't on the shift and on no other shift during it,
     * like Engelsystem's overlap check: a no-show elsewhere doesn't count
     */
    protected static function freeDuring(Shift $shift): Builder
    {
        $busy = ShiftEntry::query()
            ->where(fn($q) => $q
                ->where('shift_id', $shift->id)
                ->orWhere(fn($q) => $q
                    ->whereNull('freeloaded_by')
                    ->whereHas('shift', fn($q) => self::during($q, $shift))))
            ->select('user_id');

        return User::query()->whereHas('userAngelTypes')->whereNotIn('id', $busy);
    }

    /**
     * The entries picked on a side, all of them still there
     *
     * @param int[] $ids
     * @return Collection<ShiftEntry>
     */
    protected static function picked(array $ids, Shift $shift, AngelType $area): Collection
    {
        $entries = ShiftEntry::query()
            ->with(['user', 'shift'])
            ->whereKey($ids)
            ->where('shift_id', $shift->id)
            ->where('angel_type_id', $area->id)
            ->whereNull('freeloaded_by')
            ->get();
        if ($entries->count() !== count(array_unique($ids))) {
            throw new InvalidArgumentException(sprintf(
                '%s changed in the meantime (someone was moved or marked as no-show), nothing was moved. '
                . 'Check and tick again.',
                $shift->title
            ));
        }

        return $entries;
    }

    /**
     * The ticked ids as posted; anything else than whole numbers is refused
     *
     * @return int[]
     */
    protected static function ids(array $values): array
    {
        foreach ($values as $value) {
            if (!is_int($value) && (!is_string($value) || !ctype_digit($value) || strlen($value) > 10)) {
                throw new InvalidArgumentException('Tick the people again, nothing was moved.');
            }
        }

        return array_map('intval', $values);
    }

    /**
     * Shifts that overlap the given one
     */
    protected static function during(Builder $query, Shift $shift): Builder
    {
        return $query->where('start', '<', $shift->end)->where('end', '>', $shift->start);
    }

    protected static function sameHalf(Shift $a, Shift $b): bool
    {
        return $a->start->isSameDay($b->start) && ($a->start->hour < 12) === ($b->start->hour < 12);
    }

    /**
     * The shift's title, with the area if the title doesn't start with it
     */
    public static function name(Shift $shift, AngelType $area): string
    {
        return str_starts_with(mb_strtolower($shift->title), mb_strtolower($area->name))
            ? $shift->title
            : $area->name . ' · ' . $shift->title;
    }

    protected static function label(Shift $shift, AngelType $area): string
    {
        $staffing = Roster::staffing($shift, $area->id);

        return sprintf(
            '%s · %s–%s · %d/%d',
            self::name($shift, $area),
            $shift->start->format('H:i'),
            $shift->end->format('H:i'),
            $staffing['filled'],
            $staffing['needed']
        );
    }
}
