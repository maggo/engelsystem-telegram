<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\BaseModel;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\User\UsesUserModel;
use Illuminate\Support\Collection;

/**
 * A volunteer scanned the check-in QR code at the venue on that day, or an admin checked them in
 *
 * @property int         $id
 * @property string      $day
 * @property Carbon|null $created_at when they scanned the code; null if an admin checked them in,
 *                                   as the time they arrived isn't known then
 */
class Checkin extends BaseModel
{
    use UsesUserModel;

    public $table = 'telegram_checkins'; // phpcs:ignore

    public const UPDATED_AT = null;

    public $timestamps = true; // phpcs:ignore

    protected $fillable = ['user_id', 'day', 'created_at']; // phpcs:ignore

    /**
     * @param bool $byHand an admin checks them in, so there is no time they arrived
     * @return bool false if the user already checked in that day
     */
    public static function record(int $userId, Carbon $day, bool $byHand = false): bool
    {
        // Atomic, a second tap or a redelivered update arriving at the same time is just ignored
        $new = self::query()->insertOrIgnore([
            'user_id'    => $userId,
            'day'        => $day->toDateString(),
            'created_at' => $byHand ? null : Carbon::now(),
        ]) === 1;
        if (!$new && !$byHand) {
            // Checked in by an admin before, e.g. a lead: the scan gives the time they arrived
            self::query()
                ->where('user_id', $userId)
                ->where('day', $day->toDateString())
                ->whereNull('created_at')
                ->update(['created_at' => Carbon::now()]);
        }

        return $new;
    }

    /**
     * @param iterable<int> $userIds
     * @return int[] those of the users who checked in that day
     */
    public static function usersOn(Carbon $day, iterable $userIds): array
    {
        return self::query()
            ->where('day', $day->toDateString())
            ->whereIn('user_id', collect($userIds))
            ->pluck('user_id')
            ->all();
    }

    /**
     * Takes a check-in back, e.g. one an admin set by mistake
     *
     * @return bool false if the user hadn't checked in that day
     */
    public static function remove(int $userId, Carbon $day): bool
    {
        return self::query()->where('user_id', $userId)->where('day', $day->toDateString())->delete() > 0;
    }

    /**
     * Checks in each of the users on each of the days by hand (no time, see record()), skipping
     * check-ins that exist
     *
     * @param iterable<int>    $userIds
     * @param iterable<string> $days dates like 2026-11-03
     * @return int how many check-ins are new
     */
    public static function recordAll(iterable $userIds, iterable $days): int
    {
        $rows = [];
        foreach (collect($userIds) as $userId) {
            foreach (collect($days) as $day) {
                $rows[] = ['user_id' => $userId, 'day' => $day, 'created_at' => null];
            }
        }

        return collect($rows)
            ->chunk(500)
            ->sum(fn(Collection $chunk) => self::query()->insertOrIgnore($chunk->values()->all()));
    }

    /**
     * The day picked if it has shifts, else today if it does, else the next day with shifts, else
     * the last one
     *
     * @param Collection<string, string> $days see days()
     */
    public static function pickDay(Collection $days, ?string $day, Carbon $now): Carbon
    {
        if (!$days->has($day)) {
            $today = $now->toDateString();
            $day = $days->has($today)
                ? $today
                : $days->keys()->first(fn(string $value) => $value > $today) ?? $days->keys()->last();
        }

        return Carbon::parse($day ?? $now->toDateString());
    }

    /**
     * @return Collection<string, string> the days with shifts, date => label like "Tue Nov 3"
     */
    public static function days(): Collection
    {
        return Shift::query()
            ->selectRaw('DATE(start) AS day')
            ->distinct()
            ->orderBy('day')
            ->pluck('day')
            ->mapWithKeys(fn(string $value) => [$value => Carbon::parse($value)->format('D M j')]);
    }
}
