<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\EventConfig;
use Engelsystem\Models\News;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Illuminate\Database\Connection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Time-based bot messages:
 * - Pre-shift ping: 2 hours before start, to everyone on the shift (also when checked in already)
 * - Roll call: 15 minutes before the shift starts, leads get the list of people still not checked in
 * - News: new news with "Send notification" ticked, to everyone (NewsNotification)
 *
 * Every message is claimed before it is sent and marked as sent afterwards (Notification), and
 * forgotten again if sending failed, so it goes out once, on a later tick if need be. A message
 * can still arrive twice if Telegram took it but the answer got lost.
 */
class Scheduler
{
    /** The shift reminder goes out this long before the shift starts */
    public const PRESHIFT_MINUTES = 120;
    /** The roll call goes to the leads this long before the shift starts, when the team meets */
    public const ROLLCALL_BEFORE_MINUTES = 15;
    protected const INTERVAL_SECONDS = 30;
    /** Stored in event_config, so config('telegram_last_tick') exists too; it isn't a setting */
    protected const KEY = 'telegram_last_tick';
    protected const KEEP_DAYS = 2;

    public function __construct(
        protected Bot $bot,
        protected Leads $leads,
        protected Connection $db,
        protected LoggerInterface $log
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->bot->isConfigured();
    }

    public function tick(): bool
    {
        if (!$this->isEnabled() || !$this->claim() || !$this->lock()) {
            return false;
        }

        // Sending a few hundred pings at Telegram's rate limit takes longer than PHP's default
        set_time_limit(AfterResponse::TIME_LIMIT_SECONDS);

        try {
            Roster::asSystem(function (): void {
                $now = Carbon::now();
                $this->unfinishedUpdates();
                $this->preshiftPings($now);
                $this->rollCalls($now);
                $this->newsPings($now);
                $this->prune();
            });
        } finally {
            $this->unlock();
        }

        return true;
    }

    /**
     * Only one request every INTERVAL_SECONDS starts a tick, also across several web pods
     *
     * Runs after every page view, so it only reads until a tick is due.
     */
    protected function claim(): bool
    {
        $now = Carbon::now();
        $due = $now->copy()->subSeconds(self::INTERVAL_SECONDS);
        $last = self::lastTick();
        if ($last && $last >= $due) {
            return false;
        }

        if (!$last) {
            return $this->db->table('event_config')->insertOrIgnore([
                'name'       => self::KEY,
                'value'      => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ]) === 1;
        }

        return $this->db->table('event_config')
            ->where('name', self::KEY)
            ->where('updated_at', '<', $due)
            ->update(['updated_at' => $now]) === 1;
    }

    /**
     * A tick can take longer than INTERVAL_SECONDS; the database lock keeps a second one
     * from running at the same time and doubling the send rate
     */
    protected function lock(): bool
    {
        return (int) $this->db->selectOne('SELECT GET_LOCK(?, 0) AS locked', [$this->lockName()])->locked === 1;
    }

    protected function unlock(): void
    {
        $this->db->select('SELECT RELEASE_LOCK(?)', [$this->lockName()]);
    }

    /**
     * Database locks are per MySQL server, so another Engelsystem on it (staging) has its own
     */
    protected function lockName(): string
    {
        return $this->db->getDatabaseName() . '.' . self::KEY;
    }

    /**
     * Updates whose handling after the response was cut short
     */
    protected function unfinishedUpdates(): void
    {
        foreach (Update::claimUnfinished() as $update) {
            $this->attempt('Telegram update ' . $update->update_id, function () use ($update): void {
                $this->bot->handle(json_decode($update->payload, true) ?: []);
                Update::processed($update->update_id);
            });
        }
    }

    protected function preshiftPings(Carbon $now): void
    {
        $entries = ShiftEntry::query()
            ->with(['shift.location', 'user'])
            ->whereNull('freeloaded_by')
            ->whereHas('shift', fn($q) => $q
                ->where('start', '>', $now)
                ->where('start', '<=', $now->copy()->addMinutes(self::PRESHIFT_MINUTES)))
            ->get();

        // Only people the bot can reach; someone who connects later still gets it on a later tick
        $reachable = TelegramAccount::query()
            ->whereIn('user_id', $entries->pluck('user_id'))
            ->whereNotNull('chat_id')
            ->pluck('user_id')
            ->all();

        foreach ($entries as $entry) {
            if (!in_array($entry->user_id, $reachable)) {
                continue;
            }

            $this->sendOnce(
                [Notification::PRESHIFT, $entry->shift_id, $entry->user_id, 0],
                $now,
                fn() => $this->bot->sendPreshiftPing($entry)
            );
        }
    }

    protected function rollCalls(Carbon $now): void
    {
        $shifts = Shift::query()
            ->with(['schedule', 'neededAngelTypes'])
            ->where('start', '<=', $now->copy()->addMinutes(self::ROLLCALL_BEFORE_MINUTES))
            // Late rather than never, if nothing ticked when it was due
            ->where('end', '>', $now)
            ->get();

        foreach ($shifts as $shift) {
            foreach (Roster::needed($shift) as $needed) {
                $this->sendOnce(
                    [Notification::ROLLCALL, $shift->id, 0, $needed->angel_type_id],
                    $now,
                    fn() => $this->leads->sendRollCall($shift, $needed->angel_type_id)
                );
            }
        }
    }

    /**
     * News marked by NewsNotification, to everyone the bot can reach; a news that isn't out to
     * everyone after a day (someone blocked the bot) is given up
     */
    protected function newsPings(Carbon $now): void
    {
        $pending = Notification::query()
            ->where(['kind' => Notification::NEWS, 'user_id' => 0])
            ->whereNull('sent_at')
            ->where('created_at', '>', $now->copy()->subDay())
            ->pluck('shift_id');
        if ($pending->isEmpty()) {
            return;
        }

        $accounts = TelegramAccount::query()->whereNotNull('chat_id')->get();
        foreach (News::query()->whereIn('id', $pending)->get() as $news) {
            foreach ($accounts as $account) {
                $this->sendOnce(
                    [Notification::NEWS, $news->id, $account->user_id, 0],
                    $now,
                    fn() => $this->bot->sendNews($news, $account->chat_id)
                );
            }

            $done = Notification::query()
                ->where(['kind' => Notification::NEWS, 'shift_id' => $news->id])
                ->where('user_id', '!=', 0)
                ->whereNotNull('sent_at')
                ->count();
            if ($done >= $accounts->count()) {
                Notification::sent(Notification::NEWS, $news->id);
            }
        }
    }

    /**
     * @param array{string, int, int, int} $key kind, shift, user and area of the notification
     * @param callable(): bool $send false if it should be sent again later
     */
    protected function sendOnce(array $key, Carbon $tickStart, callable $send): void
    {
        if (!Notification::claim(...$key, tickStart: $tickStart)) {
            return;
        }

        $sent = false;
        $this->attempt($key[0] . ' for shift ' . $key[1], function () use ($send, &$sent): void {
            $sent = $send();
        });
        $sent ? Notification::sent(...$key) : Notification::forget(...$key);
    }

    /**
     * One failing message or update doesn't stop the others
     */
    protected function attempt(string $what, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            $this->log->error('{what} failed: {message} at {file}:{line}', [
                'what'    => $what,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
        }
    }

    /**
     * Forgets handled update ids and unknown contacts after a while
     */
    protected function prune(): void
    {
        $this->db->table('telegram_updates')
            ->where('created_at', '<', Carbon::now()->subDays(self::KEEP_DAYS))
            ->delete();
        UnknownContact::query()
            ->where('day', '<', Carbon::now()->subDays(self::KEEP_DAYS)->toDateString())
            ->delete();
    }

    public static function lastTick(): ?Carbon
    {
        $config = EventConfig::query()->find(self::KEY);
        return $config?->updated_at;
    }

    /**
     * From when the roll call is out and its buttons work
     */
    public static function rollCallStarted(Shift $shift): bool
    {
        return $shift->start->copy()->subMinutes(self::ROLLCALL_BEFORE_MINUTES) <= Carbon::now();
    }
}
