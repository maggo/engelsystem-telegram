<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Helpers\Authenticator;
use Engelsystem\Models\AngelType;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;

/**
 * Handles Telegram updates: commands, buttons and group membership
 *
 * Volunteers: connect (Engelsystem username = Telegram handle), complete onboarding and check in
 * (QR codes), see their shifts, tell their lead they're late or can't make it (with a reason).
 * What leads and coordinators do is in Leads.
 *
 * Everything that counts is recorded the Engelsystem way (arrival, shift entries, no-shows), so
 * the same can be done in Engelsystem when the bot is down.
 *
 * Callback data is "action:arg:arg" and must stay below Telegram's 64 bytes.
 */
class Bot
{
    /** How long a plain message counts as the reason, without replying to the question */
    protected const REASON_MINUTES = 30;

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
            $command === '/gaps'               => $this->leads->gaps($chatId, $user),
            str_starts_with($text, '/')        => $this->welcome($chatId, $user),
            default                            => $this->receiveReason($chatId, $user, $text, $message['reply_to_message']['message_id'] ?? null)
                || $this->welcome($chatId, $user),
        };
    }

    /**
     * Finds the volunteer by their linked chat, or on first contact by their Telegram handle:
     * the Engelsystem username is the handle, so people added in Engelsystem are found too; the
     * handle stored by the import covers handles that don't fit into a username
     */
    protected function identify(array $from): ?User
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

        // Matching by username is only safe while nobody can create their own account. Accounts
        // that already wait for a handle (import, reset link) only match that handle, accounts
        // linked to another Telegram user (the handle was given up and taken by someone else)
        // never match, and accounts with more rights than a volunteer never match by handle or
        // name: they connect in their settings.
        $defaultGroup = $this->auth->getDefaultRole();
        $user = TelegramAccount::waitingFor($username, $defaultGroup)?->user
            ?? (config('registration_enabled') ? null : User::query()
                ->whereRaw('LOWER(name) = ?', [$username])
                ->whereNotIn('id', TelegramAccount::query()->select('user_id'))
                ->whereDoesntHave('groups', fn($groups) => $groups->where('groups.id', '!=', $defaultGroup))
                ->first());
        if (!$user) {
            return null;
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

        $entries = Roster::upcoming($user, 3);
        $lines[] = '';
        $lines[] = $entries->isEmpty() ? 'You have no shifts yet.' : '<b>Your next shifts</b>';
        foreach ($entries as $entry) {
            $lines[] = sprintf('• %s · %s', e($entry->shift->title), $this->format->shiftTime($entry->shift));
            if ($lead = $this->format->leadLine($entry)) {
                $lines[] = '   ' . $lead;
            }
        }

        $lines[] = '';
        $lines[] = '/shifts — your shifts with meeting points';
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
            $areas = $user->userAngelTypes->pluck('name')->implode(', ');
            $lines[] = '';
            $lines[] = sprintf('You have no shift today, so you are on <b>standby</b>%s. We will message you if we need you.', $areas ? ' for ' . e($areas) : '');
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
        foreach ($entries as $entry) {
            $lines[] = '';
            $lines[] = $this->format->entry($entry, withLeads: true);
        }
        $lines[] = '';
        $lines[] = "Can't make a shift? Message your lead with the reason.";

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

        $sent = $this->api->send(
            $chatId,
            sprintf(
                "⏰ <b>Your shift starts at %s</b>\n\n%s\n\n"
                . "See you there! Running late or can't make it? Tap below. "
                . 'Missing a shift without cancelling costs your volunteer goodies.',
                $entry->shift->start->format('H:i'),
                $this->format->entry($entry, withLeads: true)
            ),
            [[
                ['text' => '🐢 Running late', 'callback_data' => 'late:' . $entry->shift_id],
                ['text' => "🚫 Can't make it", 'callback_data' => 'cant:' . $entry->shift_id],
            ]]
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

        if (!in_array($status, ['member', 'administrator'])) {
            return;
        }

        $user = isset($change['from']['id']) ? $this->identify($change['from']) : null;
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
        $user = $this->identify($message['from']);
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

        // Bad clients can send any data: only whole numbers as arguments, missing ones are 0
        // and then match nothing
        $parts = explode(':', (string) ($query['data'] ?? ''));
        $action = array_shift($parts);
        if (!$parts || array_filter($parts, fn(string $part) => !ctype_digit($part) || strlen($part) > 10)) {
            return;
        }
        $args = array_pad(array_map('intval', $parts), 3, 0);

        $user = $this->identify($query['from']);
        if (!$user) {
            return;
        }

        // The volunteer's own answers belong to their private pre-shift ping, never a group message
        $private = ($query['message']['chat']['type'] ?? '') === 'private';

        match ($action) {
            'late'  => $private ? $this->answerLate($chatId, $messageId, $user, $args[0]) : null,
            'cant'  => $private ? $this->askReason($chatId, $user, $args[0]) : null,
            'exc'   => $this->leads->decide($chatId, $messageId, $user, $args[0], excused: true),
            'unx'   => $this->leads->decide($chatId, $messageId, $user, $args[0], excused: false),
            'rexc'  => $this->leads->decide($chatId, $messageId, $user, $args[0], excused: true, rollCall: true),
            'here'  => $this->leads->markHere($chatId, $messageId, $user, $args[0]),
            'ns'    => $this->leads->markNoShow($chatId, $messageId, $user, $args[0]),
            'rep'   => $this->leads->showReplacements($chatId, $user, $args[0], $args[1]),
            'asg'   => $this->leads->assignReplacement($chatId, $messageId, $user, $args[0], $args[1], $args[2]),
            'team'  => $this->leads->team($chatId, $user, $args[0]),
            default => null,
        };
    }

    /**
     * The volunteer's entry on a shift that hasn't ended, and isn't marked as no-show yet
     */
    protected function openEntry(User $user, int $shiftId): ?ShiftEntry
    {
        return ShiftEntry::query()
            ->with('shift')
            ->where('shift_id', $shiftId)
            ->where('user_id', $user->id)
            ->whereNull('freeloaded_by')
            ->whereHas('shift', fn($q) => $q->where('end', '>', Carbon::now()))
            ->first();
    }

    protected function answerLate(int|string $chatId, int $messageId, User $user, int $shiftId): void
    {
        if (!$entry = $this->openEntry($user, $shiftId)) {
            $this->api->send($chatId, 'You are not on this shift (anymore).');
            return;
        }

        Notification::answer($entry, Notification::LATE);
        Roster::log(sprintf('%s is running late for %s %s', $user->name, $entry->shift->title, $this->format->shiftLabel($entry->shift)));

        $this->api->edit(
            $chatId,
            $messageId,
            sprintf("🐢 Thanks for letting us know, we told your lead.\n\n%s", $this->format->entry($entry, withLeads: true)),
            [[['text' => "🚫 Can't make it after all", 'callback_data' => 'cant:' . $shiftId]]]
        );
        $this->leads->sendToLeads($entry->shift, $entry->angel_type_id, sprintf(
            '🐢 %s is running late for %s.',
            $this->format->user($user),
            $this->format->shiftLabel($entry->shift)
        ));
    }

    /**
     * "Can't make it": the reason the volunteer sends in reply is the confirmation
     */
    protected function askReason(int|string $chatId, User $user, int $shiftId): void
    {
        if (!$entry = $this->openEntry($user, $shiftId)) {
            $this->api->send($chatId, 'You are not on this shift (anymore).');
            return;
        }

        $prompt = $this->api->call('sendMessage', [
            'chat_id'      => $chatId,
            'text'         => sprintf(
                "🚫 Why can't you make it to <b>%s · %s</b>?\n\n"
                . "Reply with a short reason. Your lead decides whether you're excused. "
                . "Without a reason, your shift isn't cancelled.",
                e($entry->shift->title),
                $this->format->shiftTime($entry->shift)
            ),
            'parse_mode'   => 'HTML',
            'reply_markup' => ['force_reply' => true, 'input_field_placeholder' => 'e.g. fever since last night'],
        ]);
        if (isset($prompt['message_id'])) {
            Notification::answer($entry, Notification::ASKED, (int) $prompt['message_id']);
        }
    }

    /**
     * The reason for a "can't make it": a reply to the question, or a plain message right after
     * it if only one question is open. Anything else gets the welcome.
     */
    protected function receiveReason(int|string $chatId, User $user, string $text, ?int $replyTo): bool
    {
        $open = Notification::query()
            ->where('kind', Notification::PRESHIFT)
            ->where('user_id', $user->id)
            ->where('answer', Notification::ASKED)
            ->orderByDesc('updated_at')
            ->get()
            ->filter(fn(Notification $asked) => $this->openEntry($user, $asked->shift_id) !== null);

        $asked = $replyTo
            ? $open->firstWhere('prompt_message_id', $replyTo)
            : ($open->count() === 1 && $open->first()->updated_at > Carbon::now()->subMinutes(self::REASON_MINUTES)
                ? $open->first()
                : null);
        if (!$asked && !$replyTo && $open->count() > 1) {
            $this->api->send($chatId, "You said you can't make it to more than one shift. Please reply to the question for the shift you mean (swipe it to the left).");
            return true;
        }
        if (!$asked) {
            return false;
        }

        $entry = $this->openEntry($user, $asked->shift_id);

        $reason = Roster::setCancelReason($entry, $text);
        Notification::answer($entry, Notification::CANT);
        // The reason is on the shift entry, not in the log: it may be about someone's health
        Roster::log(sprintf('%s can\'t make it to %s %s', $user->name, $entry->shift->title, $this->format->shiftLabel($entry->shift)));

        $leads = $this->leads->sendToLeads($entry->shift, $entry->angel_type_id, sprintf(
            "🚫 <b>%s can't make it</b> to %s · %s\n\n\"%s\"",
            $this->format->user($user),
            e($entry->shift->title),
            $this->format->shiftTime($entry->shift),
            e($reason)
        ), [[
            ['text' => '✅ Excuse: remove from shift', 'callback_data' => 'exc:' . $entry->id],
            ['text' => '❌ Not excused: no-show', 'callback_data' => 'unx:' . $entry->id],
        ]], except: $user);

        $this->api->send($chatId, sprintf(
            "Thanks. %s and will decide whether you're excused. Until then you stay on the shift.",
            $leads ? 'Your lead has been told' : 'The coordinators have been told'
        ));

        return true;
    }
}
