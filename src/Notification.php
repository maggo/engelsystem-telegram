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
 * @property Carbon      $updated_at
 */
class Notification extends BaseModel
{
    public const PRESHIFT = 'preshift';
    public const ROLLCALL = 'rollcall';
    /** shift_id is the news id here */
    public const NEWS = 'news';

    /** Picked as a replacement shortly before the shift, so no reminder; the roll call says so */
    public const MOVED = 'moved';
    /** Marked here by the lead in the roll call; the daily check-in at the desk doesn't count */
    public const HERE = 'here';

    public $table = 'telegram_notifications'; // phpcs:ignore

    public $timestamps = true; // phpcs:ignore

    protected $casts = [ // phpcs:ignore
        'shift_id'      => 'integer',
        'user_id'       => 'integer',
        'angel_type_id' => 'integer',
        'sent_at'       => 'datetime',
    ];

    protected $fillable = ['kind', 'shift_id', 'user_id', 'angel_type_id', 'answer']; // phpcs:ignore

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

        // Marked here early (/rollcall the evening before) still gets the reminder; a replacement
        // was told directly (MOVED, and anyone answer() marked as told has sent_at)
        return self::query()
            ->where($key)
            ->whereNull('sent_at')
            ->where(fn($q) => $q->whereNull('answer')->orWhere('answer', self::HERE))
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
     * What the roll call needs to know about the volunteer on the shift (MOVED, HERE)
     *
     * @param bool $told whether they were told about the shift directly, so they get no reminder
     */
    public static function answer(ShiftEntry $entry, string $answer, bool $told = false): void
    {
        $notification = self::query()->firstOrNew(
            ['kind' => self::PRESHIFT, 'shift_id' => $entry->shift_id, 'user_id' => $entry->user_id]
        );
        $notification->answer = $answer;
        if ($told) {
            $notification->sent_at ??= Carbon::now();
        }
        $notification->save();
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
