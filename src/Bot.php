<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Helpers\Authenticator;
use Engelsystem\Models\AngelType;
use Engelsystem\Models\EventConfig;
use Engelsystem\Models\News;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;

/**
 * Handles Telegram updates: commands, buttons and group membership
 *
 * Volunteers: connect (Engelsystem username = Telegram handle), complete onboarding and check in
 * (QR codes), see their shifts. What leads and coordinators do is in Leads.
 *
 * Everything that counts is recorded the Engelsystem way (arrival, shift entries, no-shows), so
 * the same can be done in Engelsystem when the bot is down.
 *
 * Callback data is "action:arg:arg" and must stay below Telegram's 64 bytes.
 */
class Bot
{
    protected const ME_KEY = 'telegram_bot_account';

    public function __construct(
        protected BotApi $api,
        protected Leads $leads,
        protected Format $format,
        protected Authenticator $auth
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->api->isConfigured();
    }

    public function api(): BotApi
    {
        return $this->api;
    }

    /**
     * The bot's own account (getMe), kept once Telegram answered: it only changes with the token,
     * so the admin page and the desk's QR codes don't wait for Telegram
     *
     * @return array<string, mixed>|null
     */
    public function me(): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $for = self::derivedSecret('account');
        $stored = EventConfig::query()->find(self::ME_KEY)?->value;
        if (is_array($stored['me'] ?? null) && hash_equals($for, (string) ($stored['for'] ?? ''))) {
            return $stored['me'];
        }

        $me = $this->api->call('getMe');
        if (is_array($me)) {
            EventConfig::query()->updateOrCreate(['name' => self::ME_KEY], ['value' => ['for' => $for, 'me' => $me]]);
        }

        return $me;
    }

    public static function webhookSecret(): string
    {
        return self::derivedSecret('webhook');
    }

    public static function verifyWebhookSecret(string $secret): bool
    {
        return self::matches(self::webhookSecret(), $secret);
    }

    /**
     * Key for the pinger URL; separate from the webhook secret, as URLs end up in logs
     */
    public static function tickKey(): string
    {
        return self::derivedSecret('tick');
    }

    public static function verifyTickKey(string $key): bool
    {
        return self::matches(self::tickKey(), $key);
    }

    /**
     * A secret for one purpose, derived from the bot token; empty while there is no token
     */
    public static function derivedSecret(string $purpose): string
    {
        $token = (string) config('telegram_bot_token', '');
        return $token === '' ? '' : substr(hash_hmac('sha256', $purpose, $token), 0, 32);
    }

    protected static function matches(string $expected, string $given): bool
    {
        return $expected !== '' && hash_equals($expected, trim($given));
    }

    public function handle(array $update): void
    {
        if (isset($update['my_chat_member'])) {
            $this->membershipChanged($update['my_chat_member']);
            return;
        }

        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }

        $message = $update['message'] ?? null;
        if (!$message || !isset($message['text'], $message['from']['id'])) {
            return;
        }

        $text = trim($message['text']);
        [$command, $args] = array_pad(preg_split('/\s+/', $text, 2), 2, '');
        $command = strtolower(explode('@', $command)[0]);

        if (($message['chat']['type'] ?? '') !== 'private') {
            if ($command === '/linkgroup') {
                $this->linkGroup($message, $args);
            }
            return;
        }

        $chatId = $message['chat']['id'];
        $user = $this->identify($message['from']);
        if (!$user) {
            $this->unknown($chatId, $message['from']);
            return;
        }

        match (true) {
            str_starts_with($args, 'checkin_') => $this->checkin($chatId, $user, substr($args, 8)),
            str_starts_with($args, 'done_')    => $this->completeOnboarding($chatId, $user, substr($args, 5)),
            // The code typed in, for phones that can't scan the QR code
            $command === '/checkin'            => $this->checkin($chatId, $user, $args),
            $command === '/shifts'             => $this->shifts($chatId, $user),
            $command === '/team'               => $this->leads->teamPicker($chatId, $user),
            $command === '/rollcall'           => $this->leads->rollCall($chatId, $user),
            $command === '/roster'             => $this->leads->roster($chatId, $user),
            $command === '/gaps'               => $this->leads->gaps($chatId, $user),
            default                            => $this->welcome($chatId, $user),
        };

        // After the answer, so it isn't kept waiting; picks up areas led or given up since
        $this->setCommandsFor($chatId, $user);
    }

    /**
     * The command menu everyone sees, and for each connected lead and coordinator the commands
     * only they can use (setCommandsFor()); the commands check the permission themselves
     */
    public function setCommands(): void
    {
        $everyone = $this->commandsFor(null);
        $this->api->call('setMyCommands', ['commands' => $everyone]);

        $accounts = TelegramAccount::query()->with('user')->whereNotNull('chat_id')->get();
        foreach ($accounts as $account) {
            if ($account->user && $this->commandsFor($account->user) !== $everyone) {
                $this->setCommandsFor($account->chat_id, $account->user);
            }
        }
    }

    /**
     * Gives a chat the commands its user can use, or back the menu everyone sees
     */
    protected function setCommandsFor(int|string $chatId, User $user): void
    {
        $scope = ['type' => 'chat', 'chat_id' => $chatId];
        $commands = $this->commandsFor($user);

        $commands === $this->commandsFor(null)
            ? $this->api->call('deleteMyCommands', ['scope' => $scope])
            : $this->api->call('setMyCommands', ['commands' => $commands, 'scope' => $scope]);
    }

    /**
     * The same commands /start lists (welcome())
     *
     * @return array<array{command: string, description: string}>
     */
    protected function commandsFor(?User $user): array
    {
        $commands = [['command' => 'shifts', 'description' => 'Your upcoming shifts']];
        if (!$user) {
            return $commands;
        }

        if (Roster::areasLedBy($user) || Roster::isCoordinator($user)) {
            $commands[] = ['command' => 'rollcall', 'description' => 'Roll call of your current or next shift'];
            $commands[] = ['command' => 'roster', 'description' => 'Who is on which shift, by day'];
        }
        if (Roster::areasLedBy($user)) {
            $commands[] = ['command' => 'team', 'description' => 'Your team: onboarding and check-ins'];
            $commands[] = ['command' => 'gaps', 'description' => 'Open slots in your area today and tomorrow'];
        } elseif (Roster::isCoordinator($user)) {
            $commands[] = ['command' => 'gaps', 'description' => 'Open slots today and tomorrow'];
        }

        return $commands;
    }

    /**
     * Finds the volunteer by their linked chat, or on first contact by their Telegram handle:
     * the Engelsystem username is the handle, so people added in Engelsystem are found too; the
     * handle stored by the import covers handles that don't fit into a username
     *
     * @param bool $connect false in groups: someone who hasn't started the bot yet is found, but
     *     not connected, as the bot can't message them until they do
     */
    protected function identify(array $from, bool $connect = true): ?User
    {
        $chatId = (string) $from['id'];
        $username = TelegramAccount::normalize((string) ($from['username'] ?? ''));

        /** @var TelegramAccount|null $account */
        $account = TelegramAccount::query()->where('chat_id', $chatId)->first();
        if ($account) {
            // Same Telegram user, so a changed handle can be trusted
            if ($username !== '' && TelegramAccount::normalize((string) $account->username) !== $username) {
                $account->username = $username;
                $account->save();
                $this->format->setHandle($account->user_id, $username);
            }
            return $account->user;
        }

        if ($username === '') {
            return null;
        }

        // Only accounts an admin set a handle for (import, set link) connect by handle; never by
        // the Engelsystem username, which nobody confirmed is this person's handle. Everyone else
        // connects in their settings, as do accounts with more rights than a volunteer.
        $user = TelegramAccount::waitingFor($username, $this->auth->getDefaultRole())?->user;
        if (!$user || !$connect) {
            return $user;
        }

        $account = TelegramAccount::query()->firstOrNew(['user_id' => $user->id]);

        $account->username = $username;
        $account->chat_id = $chatId;
        $account->save();
        $this->format->setHandle($user->id, $username);
        Roster::log(sprintf('%s connected Telegram (@%s)', $user->name, $username));

        return $user;
    }

    /**
     * Someone the bot can't match to an account; on onboarding day, the desk sorts it out
     */
    protected function unknown(int|string $chatId, array $from): void
    {
        // Answered and logged once a day per Telegram user, so nobody can fill the log or keep
        // the bot busy by sending messages
        if (!UnknownContact::firstToday((string) $from['id'])) {
            return;
        }

        $username = TelegramAccount::normalize((string) ($from['username'] ?? ''));
        if ($username === '') {
            Roster::log('Someone without a Telegram username tried to connect', 'warning');
            $this->api->send(
                $chatId,
                "👋 To use this bot you need a Telegram username.\n\n"
                . 'Set one in Telegram under Settings → Username, then tap Start again.'
            );
            return;
        }

        Roster::log(sprintf('@%s tried to connect but is not on the volunteer list', $username), 'warning');
        $this->api->send($chatId, sprintf(
            "❌ I couldn't find <b>@%s</b> on the volunteer list.\n\nPlease show this message at the check-in desk.",
            e($username)
        ));
    }

    /**
     * What the volunteer was set up with: area, role, onboarding status and next shifts
     */
    protected function welcome(int|string $chatId, User $user): void
    {
        $user->load(['state', 'userAngelTypes']);
        $lines = [sprintf('👋 Hi %s!', e($this->format->shortName($user))), ''];

        foreach ($user->userAngelTypes as $area) {
            $lines[] = sprintf('Area: <b>%s</b>%s', e($area->name), $area->pivot->supporter ? ' (lead)' : '');
        }
        if ($user->userAngelTypes->isNotEmpty()) {
            $lines[] = $user->state->arrived
                ? '✅ Onboarding complete, you are ready for your shifts.'
                : '⏳ Complete your onboarding with the code shown at the end of onboarding day.';
        }

        $entries = Roster::upcoming($user);
        $lines[] = '';
        $lines[] = $entries->isEmpty() ? 'You have no shifts yet.' : '<b>Your next shifts</b>';
        // Room for the commands below
        $this->format->addWithin(
            $lines,
            $entries->map(fn(ShiftEntry $entry) => implode("\n   ", array_filter([
                sprintf('• %s · %s', e($entry->shift->title), $this->format->shiftTime($entry->shift)),
                $this->format->leadLine($entry),
            ])))->all(),
            '…and %d more, see /shifts.',
            600,
            PHP_INT_MAX
        );

        $lines[] = '';
        $lines[] = '/shifts — your shifts with meeting points';
        if (Roster::areasLedBy($user) || Roster::isCoordinator($user)) {
            $lines[] = '/rollcall — roll call of the shift you lead now or next';
            $lines[] = '/roster — who is on which shift, by day';
        }
        if (Roster::areasLedBy($user)) {
            $lines[] = '/team — your team: who is onboarded and here today';
            $lines[] = '/gaps — open slots in your area';
        } elseif (Roster::isCoordinator($user)) {
            $lines[] = '/gaps — open slots today and tomorrow';
        }

        $this->api->send($chatId, implode("\n", $lines));
    }

    /**
     * End of onboarding day: the completion code marks the volunteer as arrived (trained, ready)
     */
    protected function completeOnboarding(int|string $chatId, User $user, string $code): void
    {
        if (!self::matches(Roster::onboardingCode(), $code)) {
            $this->api->send($chatId, "❌ This onboarding code isn't valid (anymore). Please scan the code shown at the end of onboarding day.");
            return;
        }

        $lines = [Roster::markArrived($user)
            ? sprintf('🎉 You are ready, %s! Your onboarding is complete.', e($this->format->shortName($user)))
            : '✅ Your onboarding was already complete.'];

        $first = Roster::upcoming($user, 1)->first();
        $lines[] = '';
        $lines[] = $first ? "<b>Your first shift</b>\n" . $this->format->entry($first, withLeads: true) : 'You have no shifts yet.';
        $lines[] = '';
        $lines[] = '📷 Scan the QR code at the check-in desk every day you are on site.';

        $this->api->send($chatId, implode("\n", $lines));
    }

    protected function checkin(int|string $chatId, User $user, string $code): void
    {
        $now = Carbon::now();
        if (!self::matches(Roster::checkinCode($now), $code)) {
            $this->api->send($chatId, "❌ This check-in code isn't valid today. Please scan today's QR code at the check-in desk.");
            return;
        }

        $new = Checkin::record($user->id, $now);
        if ($new) {
            Roster::log($user->name . ' checked in');
        }
        $lines = [$new
            ? sprintf('✅ Checked in, %s! Thanks for being here.', e($this->format->shortName($user)))
            : '✅ You already checked in today.'];

        $entries = Roster::entriesOn($user, $now);
        if ($entries->isEmpty()) {
            $lines[] = '';
            $lines[] = 'You have no shift today.';
        }

        foreach ($entries as $entry) {
            $lines[] = '';
            $lines[] = $this->format->entry($entry, withLeads: true);
        }

        $this->api->send($chatId, implode("\n", $lines));
    }

    protected function shifts(int|string $chatId, User $user): void
    {
        $entries = Roster::upcoming($user);
        if ($entries->isEmpty()) {
            $this->api->send($chatId, 'You have no upcoming shifts.');
            return;
        }

        $lines = ['<b>Your upcoming shifts</b>'];
        $this->format->addWithin(
            $lines,
            $entries->map(fn(ShiftEntry $entry) => "\n" . $this->format->entry($entry, withLeads: true))->all(),
            "\n…and %d more, see your shifts in Engelsystem.",
            max: PHP_INT_MAX
        );

        $this->api->send($chatId, implode("\n", $lines));
    }

    /**
     * @return bool false if Telegram was unavailable and the ping should be sent again later
     */
    public function sendPreshiftPing(ShiftEntry $entry): bool
    {
        $chatId = TelegramAccount::chatOf($entry->user);
        if (!$chatId) {
            return true;
        }

        $startsAt = $this->format->start($entry->shift)->format('H:i');
        $place = trim((string) config('telegram_meeting_place', ''));
        $sent = $this->api->send(
            $chatId,
            sprintf(
                "⏰ <b>Your shift starts at %s</b>\n\n%s\n\nSee you there! Please scan the QR code at the Volunteer Check-In Desk and arrive %s by <b>%s</b>.",
                $startsAt,
                $this->format->entry($entry, withLeads: true),
                $place === '' ? 'there' : 'at <b>' . e($place) . '</b>',
                $startsAt
            )
        );

        // Arrived, or failed for good (no point in sending it again)
        return $sent !== null || !$this->api->failedTemporarily();
    }

    /**
     * Only coordinators may add the bot to a group; anywhere else it leaves right away. Groups it
     * is removed from stop getting alerts.
     */
    protected function membershipChanged(array $change): void
    {
        $chat = $change['chat'] ?? [];
        $status = $change['new_chat_member']['status'] ?? '';
        if (($chat['type'] ?? 'private') === 'private' || !isset($chat['id'])) {
            return;
        }

        if (in_array($status, ['left', 'kicked'])) {
            if (Chat::query()->where('chat_id', (string) $chat['id'])->delete()) {
                Roster::log(sprintf('Bot was removed from the group "%s", it no longer gets alerts', $chat['title'] ?? $chat['id']));
            }
            return;
        }

        // Only joining is checked: a promotion or demotion inside the group isn't someone adding the bot
        $was = $change['old_chat_member']['status'] ?? 'left';
        if (!in_array($status, ['member', 'administrator']) || !in_array($was, ['left', 'kicked'])) {
            return;
        }

        $user = isset($change['from']['id']) ? $this->identify($change['from'], connect: false) : null;
        if ($chat['type'] !== 'channel' && $user && Roster::isCoordinator($user)) {
            Roster::log(sprintf('%s added the bot to the group "%s"', $user->name, $chat['title'] ?? $chat['id']));
            return;
        }

        $this->api->call('leaveChat', ['chat_id' => $chat['id']]);
        Chat::query()->where('chat_id', (string) $chat['id'])->delete();
        Roster::log(sprintf(
            'Bot left "%s": added by @%s, who is not a coordinator',
            $chat['title'] ?? $chat['id'],
            $change['from']['username'] ?? $change['from']['id'] ?? '?'
        ), 'warning');
    }

    protected function linkGroup(array $message, string $args): void
    {
        $user = $this->identify($message['from'], connect: false);
        if (!$user || !Roster::isCoordinator($user)) {
            return;
        }

        $name = trim($args);
        $area = null;
        if (!in_array(strtolower($name), ['coordination', 'coordinators', 'leads', ''])) {
            $area = AngelType::query()->where('name', $name)->first();
            if (!$area) {
                $this->api->send($message['chat']['id'], sprintf(
                    'Unknown area "%s". Use /linkgroup coordination or /linkgroup &lt;area name&gt;, e.g. /linkgroup Registration',
                    e($name)
                ));
                return;
            }
        }

        Chat::query()->updateOrCreate(
            ['chat_id' => (string) $message['chat']['id']],
            ['title' => $message['chat']['title'] ?? null, 'angel_type_id' => $area?->id]
        );

        $this->api->send($message['chat']['id'], sprintf(
            '🔗 This group now gets %s.',
            $area ? 'gap alerts for ' . e($area->name) : 'alerts for all areas (coordination)'
        ));
    }

    protected function handleCallback(array $query): void
    {
        $this->api->call('answerCallbackQuery', ['callback_query_id' => $query['id'] ?? '']);

        $chatId = $query['message']['chat']['id'] ?? null;
        $messageId = $query['message']['message_id'] ?? null;
        if (!isset($query['from']['id']) || !$chatId || !is_int($messageId)) {
            return;
        }

        // Only a button the bot put on this very message: crafted data, or a press on a message
        // too old for Telegram to include, does nothing
        $offered = collect($query['message']['reply_markup']['inline_keyboard'] ?? [])
            ->flatten(1)
            ->pluck('callback_data');
        if (!$offered->containsStrict($query['data'] ?? null)) {
            return;
        }

        // Bad clients can send any data: only whole numbers as arguments, missing ones are 0
        // and then match nothing
        $parts = explode(':', (string) ($query['data'] ?? ''));
        $action = array_shift($parts);
        if (!$parts || array_filter($parts, fn(string $part) => !ctype_digit($part) || strlen($part) > 10)) {
            return;
        }
        $args = array_pad(array_map('intval', $parts), 4, 0);

        $user = $this->identify($query['from'], connect: ($query['message']['chat']['type'] ?? '') === 'private');
        if (!$user) {
            return;
        }

        match ($action) {
            'here'  => $this->leads->markHere($chatId, $messageId, $user, $args[0]),
            'ns'    => $this->leads->markNoShow($chatId, $messageId, $user, $args[0]),
            // A message with just this button stands for one gap (unlike /gaps), so the button goes once it's filled
            'rep'   => $this->leads->showReplacements($chatId, $user, $args[0], $args[1], $messageId, $offered->count() === 1, $args[2] === 1),
            'rc'    => $this->leads->showRollCall($chatId, $messageId, $user, $args[0], $args[1]),
            'asg'   => $this->leads->assignReplacement($chatId, $messageId, $user, $args[0], $args[1], $args[2], $args[3] ?: null),
            'team'  => $this->leads->team($chatId, $user, $args[0]),
            'ros'   => $this->leads->showRoster($chatId, $messageId, $user, $args[0], $args[1]),
            'rosa'  => $this->leads->showRosterPicker($chatId, $messageId, $user),
            default => null,
        };
    }

    /**
     * A new news: title and the whole text, so nobody has to open Engelsystem for the rest
     *
     * @return bool false if Telegram was unavailable and it should be sent again later
     */
    public function sendNews(News $news, int|string $chatId): bool
    {
        $text = trim(strip_tags($news->text()));
        $sent = $this->api->send($chatId, sprintf(
            "%s <b>%s</b>\n\n%s",
            $news->is_meeting ? '📅' : '📣',
            e($news->title),
            e($this->format->cut($text, 3500))
        ));

        return $sent !== null || !$this->api->failedTemporarily();
    }
}
