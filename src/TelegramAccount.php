<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Engelsystem\Models\BaseModel;
use Engelsystem\Models\User\User;
use Engelsystem\Models\User\UsesUserModel;

/**
 * @property int         $user_id
 * @property string|null $telegram_id
 * @property string|null $chat_id
 * @property string|null $username
 */
class TelegramAccount extends BaseModel
{
    use UsesUserModel;

    public $table = 'telegram_accounts'; // phpcs:ignore

    public $incrementing = false; // phpcs:ignore

    protected $primaryKey = 'user_id'; // phpcs:ignore

    public $timestamps = true; // phpcs:ignore

    protected $casts = [ // phpcs:ignore
        'user_id' => 'integer',
    ];

    protected $fillable = [ // phpcs:ignore
        'user_id',
        'telegram_id',
        'chat_id',
        'username',
    ];

    /**
     * Telegram handles are compared without "@" and case-insensitively
     */
    public static function normalize(string $username): string
    {
        return mb_strtolower(ltrim(trim($username), '@'));
    }

    /**
     * The user's private chat with the bot, if they connected it
     */
    public static function chatOf(User $user): ?string
    {
        return self::query()->whereKey($user->id)->value('chat_id');
    }

    /**
     * The account that waits for this handle (import, reset link) and nobody connected yet;
     * accounts with more rights than a volunteer never connect by handle
     */
    public static function waitingFor(string $username, int $defaultGroupId): ?self
    {
        $username = self::normalize($username);

        return $username === '' ? null : self::query()
            ->whereRaw('LOWER(username) = ?', [$username])
            ->whereNull('chat_id')
            ->whereNull('telegram_id')
            ->whereDoesntHave('user.groups', fn($groups) => $groups->where('groups.id', '!=', $defaultGroupId))
            ->first();
    }

    public static function findByHandle(string $username): ?self
    {
        $username = self::normalize($username);

        return $username === '' ? null : self::query()->whereRaw('LOWER(username) = ?', [$username])->first();
    }
}
