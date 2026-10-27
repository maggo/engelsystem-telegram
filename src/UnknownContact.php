<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\BaseModel;

/**
 * A Telegram user the bot couldn't match to an account messaged it on that day
 *
 * @property string $telegram_user_id
 * @property string $day
 */
class UnknownContact extends BaseModel
{
    public $table = 'telegram_unknown_contacts'; // phpcs:ignore

    /**
     * Records the contact, returns false if this Telegram user already messaged the bot today
     */
    public static function firstToday(string $telegramUserId): bool
    {
        return self::query()->insertOrIgnore([
            'telegram_user_id' => $telegramUserId,
            'day'              => Carbon::now()->toDateString(),
        ]) === 1;
    }
}
