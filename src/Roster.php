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

    /** Prefix of the cancellation reason in the volunteer's comment on their shift entry */
    protected const REASON_PREFIX = "Can't make it: ";
    protected const REASON_LENGTH = 200;

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

        $gaps = new Collection();
        foreach ($shifts as $shift) {
            foreach (self::needed($shift) as $needed) {
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
     * Confirmed (or in an area without confirmation, like Engelsystem's isConfirmed), arrived
     * members of the area, not busy during the shift
     *
     * Checked-in people come first, as they are on site and can step in right away.
     *
     * @return Collection<User>
     */
    public static function replacements(Shift $shift, int $angelTypeId): Collection
    {
        // Anyone on an overlapping shift, and anyone already on this one (including no-shows)
        $busy = ShiftEntry::query()
            ->where(fn($q) => $q
                ->where('shift_id', $shift->id)
                ->orWhere(fn($q) => $q
                    ->whereNull('freeloaded_by')
                    ->whereHas('shift', fn($q) => $q->where('start', '<', $shift->end)->where('end', '>', $shift->start))))
            ->pluck('user_id');

        $candidates = User::query()
            ->with(['state', 'personalData'])
            ->whereHas('userAngelTypes', fn($q) => $q
                ->where('angel_type_id', $angelTypeId)
                ->where(fn($q) => $q->whereNotNull('confirm_user_id')->orWhere('angel_types.restricted', false)))
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
     */
    public static function conflict(User $user, Shift $shift, int $angelTypeId): ?string
    {
        if ($shift->shiftEntries()->where('user_id', $user->id)->exists()) {
            return 'already on this shift';
        }

        $staffing = self::staffing($shift, $angelTypeId);
        if ($staffing['filled'] >= $staffing['needed']) {
            return sprintf('shift is full (%d/%d)', $staffing['filled'], $staffing['needed']);
        }

        $overlapping = ShiftEntry::query()
            ->with('shift')
            ->where('user_id', $user->id)
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
     * and the insert can't interleave with another assignment to the same shift or person
     *
     * @return ShiftEntry|string the new entry, or why they couldn't be added
     */
    public static function assignReplacement(User $user, Shift $shift, int $angelTypeId, string $by): ShiftEntry|string
    {
        return app('db.connection')->transaction(function () use ($user, $shift, $angelTypeId, $by): ShiftEntry|string {
            // Always the shift first, then the person, so two assignments never wait on each other
            Shift::query()->whereKey($shift->id)->lockForUpdate()->first();
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($conflict = self::conflict($user, $shift, $angelTypeId)) {
                return $conflict;
            }
            // Only someone the replacement list offers: a confirmed, onboarded member of the area
            if (!self::replacements($shift, $angelTypeId)->contains('id', $user->id)) {
                return 'not a free member of this area';
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
     * Stores why the volunteer can't make it in their comment on the shift entry, so the reason
     * is in Engelsystem even without the bot
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
            if (str_starts_with($line, self::REASON_PREFIX)) {
                return substr($line, strlen(self::REASON_PREFIX));
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
