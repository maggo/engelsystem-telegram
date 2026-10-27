<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\BaseModel;
use Engelsystem\Models\Shifts\ShiftEntry;

/**
 * Something the scheduler sends once per shift and user (pre-shift ping) or area (roll call),
 * with the volunteer's answer; user_id and angel_type_id are 0 where they don't apply
 *
 * @property int         $id
 * @property string      $kind
 * @property int         $shift_id
 * @property int         $user_id
 * @property int         $angel_type_id
 * @property Carbon|null $sent_at
 * @property string|null $answer
 * @property int|null    $prompt_message_id
 * @property Carbon      $updated_at
 */
class Notification extends BaseModel
{
    public const PRESHIFT = 'preshift';
    public const ROLLCALL = 'rollcall';

    /** Answers to the pre-shift ping; what counts is recorded in Engelsystem (entry removed or no-show) */
    public const LATE = 'late';
    public const ASKED = 'asked';
    public const CANT = 'cant';
    public const EXCUSED = 'excused';
    public const UNEXCUSED = 'unexcused';

    public $table = 'telegram_notifications'; // phpcs:ignore

    public $timestamps = true; // phpcs:ignore

    protected $casts = [ // phpcs:ignore
        'shift_id'          => 'integer',
        'user_id'           => 'integer',
        'angel_type_id'     => 'integer',
        'sent_at'           => 'datetime',
        'prompt_message_id' => 'integer',
    ];

    protected $fillable = ['kind', 'shift_id', 'user_id', 'angel_type_id', 'answer', 'prompt_message_id']; // phpcs:ignore

    /**
     * Claims the notification for sending, returns false if it was sent or claimed already
     *
     * Atomic (unique key), so two web pods can never both send the same notification. Only
     * one tick runs at a time (Scheduler::lock()), so a claim from an earlier tick that never
     * got to sent() was cut short (worker stopped, error) and is taken over.
     */
    public static function claim(string $kind, int $shiftId, int $userId, int $angelTypeId, Carbon $tickStart): bool
    {
        $key = ['kind' => $kind, 'shift_id' => $shiftId, 'user_id' => $userId, 'angel_type_id' => $angelTypeId];
        if (self::query()->insertOrIgnore([...$key, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]) === 1) {
            return true;
        }

        return self::query()
            ->where($key)
            ->whereNull('sent_at')
            ->whereNull('answer')
            ->where('updated_at', '<', $tickStart)
            ->update(['updated_at' => Carbon::now()]) === 1;
    }

    public static function sent(string $kind, int $shiftId, int $userId = 0, int $angelTypeId = 0): void
    {
        self::query()
            ->where(['kind' => $kind, 'shift_id' => $shiftId, 'user_id' => $userId, 'angel_type_id' => $angelTypeId])
            ->update(['sent_at' => Carbon::now()]);
    }

    /**
     * The volunteer's answer to the pre-shift ping, or the lead's decision on it
     */
    public static function answer(ShiftEntry $entry, string $answer, ?int $promptMessageId = null): void
    {
        self::query()->updateOrCreate(
            ['kind' => self::PRESHIFT, 'shift_id' => $entry->shift_id, 'user_id' => $entry->user_id],
            ['answer' => $answer, 'prompt_message_id' => $promptMessageId]
        );
    }

    /**
     * Undoes claim() when sending failed, so the next tick tries again
     */
    public static function forget(string $kind, int $shiftId, int $userId = 0, int $angelTypeId = 0): void
    {
        self::query()
            ->where('kind', $kind)
            ->where('shift_id', $shiftId)
            ->where('user_id', $userId)
            ->where('angel_type_id', $angelTypeId)
            ->whereNull('answer')
            ->delete();
    }
}
