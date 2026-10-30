<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Logger\Logger;
use Engelsystem\Models\AngelType;
use Engelsystem\Models\LogEntry;
use Engelsystem\Models\Shifts\NeededAngelType;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;
use Engelsystem\Models\UserAngelType;
use Illuminate\Support\Collection;

/**
 * Read and write helpers around areas (angel types), shifts and who is on them
 *
 * A lead is a supporter of the area who is assigned to the shift.
 */
class Roster
{
    public const LOG_PREFIX = 'Bot: ';

    /** While the scheduler runs after someone's page view, see asSystem() */
    protected static bool $system = false;

    /** Prefix of the volunteer's message to the lead in their comment on their shift entry */
    protected const REASON_PREFIX = "Late or can't come: ";
    /** What 1.0 wrote, still read for cancellations made before the update */
    protected const OLD_REASON_PREFIX = "Can't make it: ";
    protected const REASON_LENGTH = 200;
    public const ALREADY_ON_SHIFT = 'already on this shift';
    /** A standby shift that starts within this time can give replacements already */
    protected const STANDBY_SOON_MINUTES = 60;

    /**
     * @return Collection<ShiftEntry> the user's shift entries starting on the given day
     */
    public static function entriesOn(User $user, Carbon $day): Collection
    {
        return ShiftEntry::query()
            ->with(['shift.location', 'angelType'])
            ->where('user_id', $user->id)
            ->whereHas('shift', fn($q) => $q->whereBetween('start', [
                $day->copy()->startOfDay(),
                $day->copy()->endOfDay(),
            ]))
            ->get()
            ->sortBy(fn(ShiftEntry $entry) => $entry->shift->start)
            ->values();
    }

    /**
     * @return Collection<ShiftEntry> upcoming shift entries of the user
     */
    public static function upcoming(User $user, int $limit = 6): Collection
    {
        return ShiftEntry::query()
            ->with(['shift.location', 'angelType'])
            ->where('user_id', $user->id)
            ->whereHas('shift', fn($q) => $q->where('end', '>', Carbon::now()))
            ->get()
            ->sortBy(fn(ShiftEntry $entry) => $entry->shift->start)
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<User> supporters of the area who are on the shift, not as no-show
     */
    public static function leadsOf(Shift $shift, int $angelTypeId): Collection
    {
        $supporterIds = UserAngelType::query()
            ->where('angel_type_id', $angelTypeId)
            ->where('supporter', true)
            ->pluck('user_id');

        return User::query()
            ->with('personalData')
            ->whereIn('id', $supporterIds)
            ->whereIn('id', $shift->shiftEntries()->whereNull('freeloaded_by')->pluck('user_id'))
            ->get();
    }

    /**
     * @return AngelType[] areas the user leads
     */
    public static function areasLedBy(User $user): array
    {
        return $user->userAngelTypes()->wherePivot('supporter', true)->get()->all();
    }

    public static function isSupporter(int $userId, int $angelTypeId): bool
    {
        return UserAngelType::query()
            ->where('user_id', $userId)
            ->where('angel_type_id', $angelTypeId)
            ->where('supporter', true)
            ->exists();
    }

    public static function isCoordinator(User $user): bool
    {
        // The same permission as the plugin's admin pages
        return $user->privileges->contains('name', 'admin_user');
    }

    /**
     * How many people per area the shift needs, from where Engelsystem takes it: the shift
     * itself, or for shifts from a schedule its shift type or location (NeededAngelTypes_by_shift())
     *
     * @return Collection<NeededAngelType>
     */
    public static function needed(Shift $shift): Collection
    {
        $schedule = $shift->schedule;
        if (!$schedule && $shift->relationLoaded('neededAngelTypes')) {
            return $shift->neededAngelTypes;
        }

        return NeededAngelType::query()
            ->with('angelType')
            ->when(
                !$schedule,
                fn($q) => $q->where('shift_id', $shift->id),
                fn($q) => $schedule->needed_from_shift_type
                    ? $q->where('shift_type_id', $shift->shift_type_id)
                    : $q->where('location_id', $shift->location_id)
            )
            ->get();
    }

    /**
     * @return array{needed: int, filled: int} per area on a shift; no-shows don't count as filled
     */
    public static function staffing(Shift $shift, int $angelTypeId): array
    {
        $needed = (int) self::needed($shift)->where('angel_type_id', $angelTypeId)->sum('count');
        $filled = $shift->shiftEntries()
            ->where('angel_type_id', $angelTypeId)
            ->whereNull('freeloaded_by')
            ->count();

        return ['needed' => $needed, 'filled' => $filled];
    }

    /**
     * @return Collection<array{shift: Shift, angel_type: AngelType, needed: int, filled: int}> open slots
     */
    public static function gaps(Carbon $from, Carbon $until): Collection
    {
        $shifts = Shift::query()
            ->with(['schedule', 'neededAngelTypes.angelType', 'shiftEntries'])
            ->where('end', '>', $from)
            ->where('start', '<', $until)
            ->orderBy('start')
            ->get();
        // Standby shrinks as people step in, that's not a gap to fill
        $standby = self::standbyArea();

        $gaps = new Collection();
        foreach ($shifts as $shift) {
            foreach (self::needed($shift) as $needed) {
                if ($needed->angel_type_id === $standby?->id) {
                    continue;
                }
                $filled = $shift->shiftEntries
                    ->where('angel_type_id', $needed->angel_type_id)
                    ->whereNull('freeloaded_by')
                    ->count();
                if ($filled < $needed->count) {
                    $gaps->push([
                        'shift'      => $shift,
                        'angel_type' => $needed->angelType,
                        'needed'     => $needed->count,
                        'filled'     => $filled,
                    ]);
                }
            }
        }

        return $gaps;
    }

    /**
     * The area whose volunteers wait to step into any other area's shift, set in Settings >
     * Telegram; null if there is none
     */
    public static function standbyArea(): ?AngelType
    {
        $name = self::standbyName();

        return $name === '' ? null : AngelType::query()->where('name', $name)->orderBy('id')->first();
    }

    /**
     * The standby area's name as set, even if no area has that name (a typo, or renamed since)
     */
    public static function standbyName(): string
    {
        return trim((string) config('telegram_standby_area', ''));
    }

    /**
     * Standby entries whose people can replace someone on the shift: on a standby shift that
     * covers most of it (so the morning standby isn't offered for the afternoon shift) and runs
     * now or starts within the hour, not marked as no-show, and not cancelled; who isn't checked in
     * yet comes last (see replacements())
     *
     * @return Collection<ShiftEntry>
     */
    public static function standbyFor(Shift $shift, int $angelTypeId): Collection
    {
        $standby = self::standbyArea();
        if (!$standby || $standby->id === $angelTypeId) {
            return new Collection();
        }

        $now = Carbon::now();
        $entries = ShiftEntry::query()
            ->with('shift')
            ->where('angel_type_id', $standby->id)
            ->where('shift_id', '!=', $shift->id)
            ->whereNull('freeloaded_by')
            ->whereHas('shift', fn($q) => $q
                ->where('start', '<', $shift->end)
                ->where('end', '>', $shift->start)
                ->where('start', '<=', $now->copy()->addMinutes(self::STANDBY_SOON_MINUTES))
                ->where('end', '>', $now))
            ->get()
            ->filter(fn(ShiftEntry $entry) => self::coversMost($entry->shift, $shift));

        // Wrote they're late or can't come (or are about to): not someone to send anywhere
        $cancelled = Notification::query()
            ->where('kind', Notification::PRESHIFT)
            ->whereIn('shift_id', $entries->pluck('shift_id'))
            ->whereIn('answer', [Notification::ASKED, Notification::CANT])
            ->get(['shift_id', 'user_id'])
            ->map(fn(Notification $n) => $n->shift_id . ':' . $n->user_id)
            ->all();

        return $entries
            ->reject(fn(ShiftEntry $entry) => in_array($entry->shift_id . ':' . $entry->user_id, $cancelled))
            ->values();
    }

    protected static function coversMost(Shift $outer, Shift $shift): bool
    {
        $overlap = min($outer->end->getTimestamp(), $shift->end->getTimestamp())
            - max($outer->start->getTimestamp(), $shift->start->getTimestamp());

        return $overlap * 2 >= $shift->end->getTimestamp() - $shift->start->getTimestamp();
    }

    /**
     * Who can step in: with a standby area, only the people on standby during the shift (see
     * standbyFor()), as everyone else is on a shift of their own; without one, confirmed (or in
     * an area without confirmation, like Engelsystem's isConfirmed), arrived members of the area
     * who aren't busy during the shift
     *
     * Checked-in people come first, as they are on site and can step in right away. Each has
     * `checked_in` set.
     *
     * @return Collection<User>
     */
    public static function replacements(Shift $shift, int $angelTypeId): Collection
    {
        $standby = self::standbyFor($shift, $angelTypeId);
        $standbyArea = self::standbyArea();

        // Anyone on an overlapping shift, and anyone already on this one (including no-shows);
        // standby doesn't count, that's what it is for (also the other half day's standby,
        // which overlaps at the handover)
        $busy = ShiftEntry::query()
            ->where(fn($q) => $q
                ->where('shift_id', $shift->id)
                ->orWhere(fn($q) => $q
                    ->whereNull('freeloaded_by')
                    ->when($standbyArea, fn($q) => $q->where('angel_type_id', '!=', $standbyArea->id))
                    ->whereHas('shift', fn($q) => $q->where('start', '<', $shift->end)->where('end', '>', $shift->start))))
            ->pluck('user_id');

        $candidates = User::query()
            ->with(['state', 'personalData'])
            ->when(
                // Set but misspelled offers nobody rather than people who are on their own shift
                self::standbyName() !== '',
                fn($q) => $q->whereIn('id', $standby->pluck('user_id')),
                fn($q) => $q->whereHas('userAngelTypes', fn($q) => $q
                    ->where('angel_type_id', $angelTypeId)
                    ->where(fn($q) => $q->whereNotNull('confirm_user_id')->orWhere('angel_types.restricted', false)))
            )
            ->whereNotIn('id', $busy)
            ->get()
            ->filter(fn(User $user) => $user->state->arrived);

        $checkedIn = Checkin::usersOn($shift->start, $candidates->pluck('id'));

        return $candidates
            ->each(fn(User $user) => $user->setAttribute('checked_in', in_array($user->id, $checkedIn)))
            ->sortBy(fn(User $user) => [!$user->checked_in, $user->name])
            ->values();
    }

    /**
     * Why the user can't be put on the shift, or null if they can: the same checks the
     * Engelsystem UI does for sign-ups (shift full, overlapping shift)
     *
     * @param int[] $leaving entries the user is about to leave, which don't count as overlapping
     */
    public static function conflict(User $user, Shift $shift, int $angelTypeId, array $leaving = []): ?string
    {
        if ($shift->shiftEntries()->where('user_id', $user->id)->exists()) {
            return self::ALREADY_ON_SHIFT;
        }

        $staffing = self::staffing($shift, $angelTypeId);
        if ($staffing['filled'] >= $staffing['needed']) {
            return sprintf('shift is full (%d/%d)', $staffing['filled'], $staffing['needed']);
        }

        $overlapping = ShiftEntry::query()
            ->with('shift')
            ->where('user_id', $user->id)
            ->whereNotIn('id', $leaving)
            ->whereNull('freeloaded_by')
            ->whereHas('shift', fn($q) => $q->where('start', '<', $shift->end)->where('end', '>', $shift->start))
            ->first();
        if ($overlapping) {
            return sprintf(
                'overlaps with %s %s',
                $overlapping->shift->title,
                $overlapping->shift->start->format('D M j H:i')
            );
        }

        return null;
    }

    /**
     * Adds a replacement if they are still free and the shift still needs someone; the checks
     * and the insert can't interleave with another assignment to the same shift or person.
     * Someone on standby is moved: their standby entry is removed.
     *
     * @return ShiftEntry|string the new entry, or why they couldn't be added
     */
    public static function assignReplacement(User $user, Shift $shift, int $angelTypeId, string $by): ShiftEntry|string
    {
        return app('db.connection')->transaction(function () use ($user, $shift, $angelTypeId, $by): ShiftEntry|string {
            // Always the shift first, then the person, so two assignments never wait on each other
            Shift::query()->whereKey($shift->id)->lockForUpdate()->first();
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($shift->end <= Carbon::now()) {
                return 'this shift is over';
            }

            // They leave the standby shift covering this one; another standby shift overlapping
            // at the handover stays (they served it), but doesn't block the move either
            $standby = self::standbyFor($shift, $angelTypeId)->where('user_id', $user->id);
            $standbyArea = self::standbyArea();
            $overlappingStandby = $standbyArea ? ShiftEntry::query()
                ->where('user_id', $user->id)
                ->where('angel_type_id', $standbyArea->id)
                ->whereHas('shift', fn($q) => $q->where('start', '<', $shift->end)->where('end', '>', $shift->start))
                ->pluck('id')
                ->all() : [];
            if ($conflict = self::conflict($user, $shift, $angelTypeId, $overlappingStandby)) {
                return $conflict;
            }
            // Only someone the replacement list offers
            if (!self::replacements($shift, $angelTypeId)->contains('id', $user->id)) {
                return self::standbyName() !== '' ? 'not on standby right now' : 'not a free member of this area';
            }

            // As in Engelsystem, a lead only puts members of their area on its shifts: they join it,
            // the way a lead adds someone to their area there (confirmed right away)
            if (!$user->userAngelTypes()->wherePivot('angel_type_id', $angelTypeId)->exists()) {
                UserAngelType::query()->create([
                    'user_id'         => $user->id,
                    'angel_type_id'   => $angelTypeId,
                    'confirm_user_id' => $user->id,
                    'supporter'       => false,
                ]);
                self::log(sprintf(
                    'User %s added to angel type %s, confirmed: true, supporter: false.',
                    $user->name,
                    AngelType::query()->find($angelTypeId)?->name
                ));
            }

            foreach ($standby as $entry) {
                self::unassign($entry, sprintf(
                    'moved to %s (%s) as a replacement by %s',
                    $shift->title,
                    $shift->start->format('Y-m-d H:i'),
                    $by
                ));
            }

            return self::assign($user, $shift, $angelTypeId, $by);
        });
    }

    public static function assign(User $user, Shift $shift, int $angelTypeId, string $by): ShiftEntry
    {
        /** @var ShiftEntry $entry */
        $entry = ShiftEntry::query()->firstOrCreate(
            ['shift_id' => $shift->id, 'user_id' => $user->id],
            ['angel_type_id' => $angelTypeId]
        );

        self::log(sprintf(
            'User %s assigned to shift %s (%s) as %s by %s',
            $user->name,
            $shift->title,
            $shift->start->format('Y-m-d H:i'),
            $entry->angelType->name,
            $by
        ));

        return $entry;
    }

    public static function unassign(ShiftEntry $entry, string $reason): void
    {
        self::log(sprintf(
            'User %s removed from shift %s (%s): %s',
            $entry->user->name,
            $entry->shift->title,
            $entry->shift->start->format('Y-m-d H:i'),
            $reason
        ));

        $entry->delete();
    }

    public static function markNoShow(ShiftEntry $entry, User $by, ?string $comment = null): void
    {
        $entry->freeloaded_by = $by->id;
        $entry->freeloaded_comment = $comment ?? 'No-show, reported via Telegram by ' . $by->name;
        $entry->save();

        self::log(sprintf(
            'User %s marked as no-show for shift %s (%s) by %s',
            $entry->user->name,
            $entry->shift->title,
            $entry->shift->start->format('Y-m-d H:i'),
            $by->name
        ));
    }

    /**
     * The volunteer completed onboarding; Engelsystem calls it arrived
     */
    public static function markArrived(User $user): bool
    {
        if ($user->state->arrived) {
            return false;
        }

        $user->state->arrival_date = Carbon::now();
        $user->state->save();
        self::log($user->name . ' completed onboarding (arrived)');

        return true;
    }

    /**
     * Stores the volunteer's message to the lead (late or can't come) in their comment on the
     * shift entry, so it is in Engelsystem even without the bot
     *
     * @return string the reason as stored
     */
    public static function setCancelReason(ShiftEntry $entry, string $reason): string
    {
        $reason = mb_substr(trim($reason), 0, self::REASON_LENGTH);
        $entry->user_comment = trim(self::REASON_PREFIX . $reason . "\n" . (string) $entry->user_comment);
        $entry->save();

        return $reason;
    }

    public static function cancelReason(ShiftEntry $entry): string
    {
        foreach (preg_split('/\R/', (string) $entry->user_comment) as $line) {
            foreach ([self::REASON_PREFIX, self::OLD_REASON_PREFIX] as $prefix) {
                if (str_starts_with($line, $prefix)) {
                    return substr($line, strlen($prefix));
                }
            }
        }

        return '';
    }

    /**
     * Writes to the Engelsystem log (Admin > Log), like the core's engelsystem_log(); the prefix
     * lets the dashboard show the bot's activity
     */
    public static function log(string $message, string $level = 'info'): void
    {
        $logger = self::$system ? new Logger(new LogEntry()) : app('logger');
        $logger->log($level, self::LOG_PREFIX . $message);
    }

    /**
     * Runs scheduled work: Engelsystem's logger would name the user whose page view started it
     * as the one who did what the bot logs
     */
    public static function asSystem(callable $work): mixed
    {
        $was = self::$system;
        self::$system = true;
        try {
            return $work();
        } finally {
            self::$system = $was;
        }
    }

    /**
     * Today's check-in code, shown as QR code at the desk; changes daily
     */
    public static function checkinCode(?Carbon $day = null): string
    {
        return self::dailyCode('checkin', $day);
    }

    /**
     * Code shown at the end of onboarding day; scanning it marks the volunteer as arrived
     */
    public static function onboardingCode(?Carbon $day = null): string
    {
        return self::dailyCode('onboarding', $day);
    }

    /**
     * Empty while the bot has no token, so no code is valid then
     */
    protected static function dailyCode(string $purpose, ?Carbon $day): string
    {
        $secret = Bot::derivedSecret('codes');

        return $secret === ''
            ? ''
            : substr(hash_hmac('sha256', $purpose . ':' . ($day ?? Carbon::now())->toDateString(), $secret), 0, 8);
    }
}
