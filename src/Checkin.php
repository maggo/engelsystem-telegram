<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\BaseModel;
use Engelsystem\Models\User\UsesUserModel;

/**
 * A volunteer scanned the check-in QR code at the venue on that day
 *
 * @property int    $id
 * @property string $day
 * @property Carbon $created_at
 */
class Checkin extends BaseModel
{
    use UsesUserModel;

    public $table = 'telegram_checkins'; // phpcs:ignore

    public const UPDATED_AT = null;

    public $timestamps = true; // phpcs:ignore

    protected $fillable = ['user_id', 'day', 'created_at']; // phpcs:ignore

    /**
     * @return bool false if the user already checked in that day
     */
    public static function record(int $userId, Carbon $day): bool
    {
        // Atomic, a second tap or a redelivered update arriving at the same time is just ignored
        return self::query()->insertOrIgnore([
            'user_id'    => $userId,
            'day'        => $day->toDateString(),
            'created_at' => Carbon::now(),
        ]) === 1;
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
}
