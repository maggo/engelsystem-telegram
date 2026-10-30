<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\BaseModel;
use Illuminate\Support\Collection;

/**
 * An update from Telegram, stored before it is acknowledged
 *
 * Telegram only redelivers updates the site didn't acknowledge, so an update whose handling was
 * cut short (worker stopped, error) is handled again by the scheduler, a few times at most.
 *
 * @property int         $update_id
 * @property string      $payload
 * @property int         $attempts
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 */
class Update extends BaseModel
{
    public const MAX_ATTEMPTS = 3;

    /** Handling after the response normally takes seconds; older unfinished updates were cut short */
    protected const RETRY_AFTER_SECONDS = 120;

    public $table = 'telegram_updates'; // phpcs:ignore

    public $incrementing = false; // phpcs:ignore

    protected $primaryKey = 'update_id'; // phpcs:ignore

    protected $casts = [ // phpcs:ignore
        'update_id'    => 'integer',
        'attempts'     => 'integer',
        'processed_at' => 'datetime',
        'created_at'   => 'datetime',
    ];

    /**
     * @return bool false if Telegram delivered this update before
     */
    public static function record(int $updateId, string $payload): bool
    {
        return self::query()->insertOrIgnore([
            'update_id'  => $updateId,
            'payload'    => $payload,
            'created_at' => Carbon::now(),
        ]) === 1;
    }

    public static function processed(int $updateId): void
    {
        // The payload is only kept for a retry; it can hold a cancellation reason
        self::query()->whereKey($updateId)->update(['processed_at' => Carbon::now(), 'payload' => '']);
    }

    /**
     * Claims the updates whose handling was cut short, for one more attempt each
     *
     * @return Collection<Update>
     */
    public static function claimUnfinished(): Collection
    {
        $updates = self::query()
            ->whereNull('processed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('created_at', '<', Carbon::now()->subSeconds(self::RETRY_AFTER_SECONDS))
            ->orderBy('update_id')
            ->limit(50)
            ->get();

        return $updates->filter(fn(Update $update) => self::query()
            ->whereKey($update->update_id)
            ->where('attempts', $update->attempts)
            ->whereNull('processed_at')
            ->update(['attempts' => $update->attempts + 1]) === 1)->values();
    }
}
