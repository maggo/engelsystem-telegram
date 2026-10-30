<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\AngelType;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;
use Engelsystem\Models\UserAngelType;

/**
 * What leads (supporters of an area) and coordinators do in the bot: team roster, roll call,
 * deciding on cancellations, no-shows, open slots and replacements
 *
 * Every action checks that the user may lead the area: Telegram clients can send any callback data.
 */
class Leads
{
    public function __construct(protected BotApi $api, protected Format $format)
    {
    }

    /**
     * Sends the leads of the shift the list of people not checked in yet, or the coordination
     * group if no lead could be reached
     *
     * @return bool false if Telegram was unavailable and nobody got it, so it should be sent again later
     */
    public function sendRollCall(Shift $shift, int $angelTypeId): bool
    {
        [$text, $buttons] = $this->rollCallMessage($shift, $angelTypeId);

        $sent = false;
        $retry = false;
        foreach (Roster::leadsOf($shift, $angelTypeId) as $lead) {
            if ($chatId = TelegramAccount::chatOf($lead)) {
                $sent = $this->api->send($chatId, $text, $buttons) !== null || $sent;
                $retry = $retry || $this->api->failedTemporarily();
            }
        }
        $to = 'the lead';

        if (!$sent) {
            foreach (Chat::forArea(null) as $chat) {
                $sent = $this->api->send($chat->chat_id, $this->toCoordinationNote($shift, $angelTypeId) . "\n\n" . $text, $buttons) !== null || $sent;
                $retry = $retry || $this->api->failedTemporarily();
            }
            $to = 'coordination, no lead reachable';
        }

        if ($sent) {
            Roster::log(sprintf('Roll call for %s %s sent to %s', $shift->title, $this->format->shiftLabel($shift), $to));
        }

        return $sent || !$retry;
    }

    /**
     * @return array{string, array}
     */
    protected function rollCallMessage(Shift $shift, int $angelTypeId): array
    {
        $area = AngelType::query()->find($angelTypeId);
        // The leads take the roll call, they aren't on it
        $leadIds = Roster::leadsOf($shift, $angelTypeId)->pluck('id')->all();
        $entries = $shift->shiftEntries()
            ->with('user.personalData')
            ->where('angel_type_id', $angelTypeId)
            ->whereNotIn('user_id', $leadIds)
            ->get();
        $this->format->loadHandles($entries->pluck('user_id'));
        $checkedIn = Checkin::usersOn($shift->start, $entries->pluck('user_id'));
        $answers = Notification::query()
            ->where('kind', Notification::PRESHIFT)
            ->where('shift_id', $shift->id)
            ->whereIn('user_id', $entries->pluck('user_id'))
            ->pluck('answer', 'user_id');

        // A replacement, or someone who wrote they're late or can't come, isn't here until the lead says
        // so, even if checked in for the day
        $missing = $entries->filter(fn(ShiftEntry $entry) => !$entry->freeloaded_by
            && (!in_array($entry->user_id, $checkedIn)
                || in_array($answers[$entry->user_id] ?? null, [Notification::MOVED, Notification::CANT])));
        $noShows = $entries->filter(fn(ShiftEntry $entry) => (bool) $entry->freeloaded_by);
        $staffing = Roster::staffing($shift, $angelTypeId);

        $lines = [sprintf(
            '📋 <b>Roll call: %s</b> (%s)',
            e($shift->title),
            $this->format->shiftTime($shift)
        )];
        // Volunteers only, the leads take the roll call; the lead's slot is left out of "needed" too,
        // so it adds up with the "short" line below
        $leadSlots = $shift->shiftEntries()
            ->where('angel_type_id', $angelTypeId)
            ->whereIn('user_id', $leadIds)
            ->whereNull('freeloaded_by')
            ->count();
        $staying = $entries->count() - $noShows->count();
        $lines[] = sprintf(
            '%d/%d volunteers on the shift · %s',
            $staying,
            max(0, $staffing['needed'] - $leadSlots),
            match (true) {
                $staying === $missing->count() => 'nobody here yet',
                $missing->isEmpty()            => 'all here',
                default                        => sprintf('%d here', $staying - $missing->count()),
            }
        );

        $buttons = [];
        if ($missing->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<b>Not checked in:</b>';
            $missing = $missing->values();
            $note = fn(ShiftEntry $entry) => match ($answers[$entry->user_id] ?? null) {
                Notification::MOVED => ' — replacement, not seen here yet',
                Notification::CANT  => ' — wrote: "' . e($this->format->cut(Roster::cancelReason($entry), 80)) . '"',
                default             => '',
            };
            $names = $missing->map(fn(ShiftEntry $entry) => '• ' . $this->format->user($entry->user) . $note($entry));
            $listed = $this->format->addWithin($lines, $names->all(), '…and %d more, see the shift in Engelsystem.', 500);
            foreach ($missing->take($listed) as $entry) {
                $answer = $answers[$entry->user_id] ?? null;
                $buttons[] = [
                    ['text' => '✅ ' . $this->format->shortName($entry->user) . ' is here', 'callback_data' => 'here:' . $entry->id],
                    ...($answer === Notification::CANT ? [['text' => '🙏 Excuse', 'callback_data' => 'rexc:' . $entry->id]] : []),
                    ['text' => '❌ No-show', 'callback_data' => 'ns:' . $entry->id],
                ];
            }
        } elseif ($entries->count() > $noShows->count() && $staffing['filled'] >= $staffing['needed']) {
            // While short, the header ("all here") and the "short" line say it all
            $lines[] = '';
            $lines[] = '🎉 Everyone is here.';
        }

        if ($noShows->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<b>No-shows:</b> ' . $noShows->take(10)->map(fn($entry) => $this->format->user($entry->user))->implode(', ')
                . ($noShows->count() > 10 ? sprintf(' and %d more', $noShows->count() - 10) : '');
        }

        // Standby isn't refilled, see alertGap()
        if ($staffing['filled'] < $staffing['needed'] && $angelTypeId !== Roster::standbyArea()?->id) {
            $lines[] = '';
            $lines[] = sprintf('⚠️ %s is %d short.', e($area?->name), $staffing['needed'] - $staffing['filled']);
            // In place: the roll call turns into the list and back, one message per shift
            $buttons[] = [['text' => '🔁 Find replacement', 'callback_data' => 'rep:' . $shift->id . ':' . $angelTypeId . ':1']];
        }

        return [implode("\n", $lines), $buttons];
    }

    public function teamPicker(int|string $chatId, User $user): void
    {
        $areas = Roster::areasLedBy($user);
        if (Roster::isCoordinator($user) && !$areas) {
            $areas = AngelType::query()->orderBy('name')->get()->all();
        }

        if (!$areas) {
            $this->api->send($chatId, 'Only leads can see their team.');
            return;
        }

        if (count($areas) === 1) {
            $this->team($chatId, $user, $areas[0]->id);
            return;
        }

        $this->api->send($chatId, 'Which team?', array_map(
            fn(AngelType $area) => [['text' => $area->name, 'callback_data' => 'team:' . $area->id]],
            $areas
        ));
    }

    /**
     * Team roster: who completed onboarding and who checked in today
     */
    public function team(int|string $chatId, User $user, int $angelTypeId): void
    {
        $area = AngelType::query()->find($angelTypeId);
        if (!$area || !$this->canLead($user, $angelTypeId)) {
            return;
        }

        $members = User::query()
            ->with(['state', 'personalData'])
            ->whereHas('userAngelTypes', fn($q) => $q->where('angel_type_id', $angelTypeId))
            ->orderBy('name')
            ->get();
        $this->format->loadHandles($members->pluck('id'));
        $supporters = UserAngelType::query()
            ->where('angel_type_id', $angelTypeId)
            ->where('supporter', true)
            ->pluck('user_id')
            ->all();
        $today = Checkin::usersOn(Carbon::now(), $members->pluck('id'));

        $notOnboarded = $members->filter(fn(User $member) => !$member->state->arrived);
        $lines = [sprintf(
            '👥 <b>%s</b> — %d members, %d onboarded, %d checked in today',
            e($area->name),
            $members->count(),
            $members->count() - $notOnboarded->count(),
            count($today)
        )];
        $lines[] = '';
        // Big teams: only list who is still missing, the message would get too long otherwise
        $listed = $members->count() > 30 ? $notOnboarded : $members;
        if ($listed->isEmpty()) {
            $lines[] = '🎉 Everyone has completed onboarding.';
        }
        $this->format->addWithin($lines, $listed->map(fn(User $member) => sprintf(
            '%s %s%s%s',
            $member->state->arrived ? '✅' : '⏳',
            $this->format->user($member),
            in_array($member->id, $supporters) ? ' (lead)' : '',
            in_array($member->id, $today) ? ' · 📍 here' : ''
        ))->values()->all(), '…and %d more, see the dashboard in Engelsystem.');
        $lines[] = '';
        $lines[] = '✅ onboarding complete · ⏳ not yet · 📍 checked in today';

        $this->api->send($chatId, implode("\n", $lines));
    }

    public function gaps(int|string $chatId, User $user): void
    {
        $areas = collect(Roster::areasLedBy($user))->pluck('id');
        $coordinator = Roster::isCoordinator($user);
        if ($areas->isEmpty() && !$coordinator) {
            $this->api->send($chatId, 'Only leads and coordinators can see gaps.');
            return;
        }

        $now = Carbon::now();
        $until = $now->copy()->addDay()->endOfDay();
        $gaps = Roster::gaps($now, $until)
            ->filter(fn($gap) => $coordinator || $areas->contains($gap['angel_type']->id));

        if ($gaps->isEmpty()) {
            $this->api->send($chatId, '🎉 No open slots today or tomorrow.');
            return;
        }

        $lines = ['<b>Open slots today and tomorrow</b>', ''];
        $gaps = $gaps->values();
        $this->format->addWithin($lines, $gaps->map(fn($gap) => sprintf(
            '• %s · %s — %d/%d',
            e($gap['angel_type']->name),
            $this->format->shiftLabel($gap['shift']),
            $gap['filled'],
            $gap['needed']
        ))->all(), '…and %d more, see the shifts in Engelsystem.');
        $buttons = [];
        foreach ($gaps as $gap) {
            $buttons[] = [[
                'text'          => '🔁 ' . $gap['angel_type']->name . ' ' . $this->format->shiftLabel($gap['shift']),
                'callback_data' => 'rep:' . $gap['shift']->id . ':' . $gap['angel_type']->id,
            ]];
        }

        $this->api->send($chatId, implode("\n", $lines), array_slice($buttons, 0, 20));
    }

    /**
     * The lead's decision on a cancellation, recorded the way Engelsystem does it: excused means
     * off the shift, not excused means marked as a no-show (freeloaded)
     */
    public function decide(
        int|string $chatId,
        ?int $messageId,
        User $lead,
        int $entryId,
        bool $excused,
        bool $rollCall = false
    ): void {
        /** @var ShiftEntry|null $entry */
        $entry = ShiftEntry::query()->with(['shift', 'user'])->find($entryId);
        // An entry that's gone (excused) can't tell whose area it was: nothing to do
        if (!$entry || !$this->canLead($lead, $entry->angel_type_id) || $this->isOwn($lead, $entry)) {
            return;
        }

        // The buttons only come with a cancellation, and only until it's decided
        $pending = Notification::query()
            ->where('kind', Notification::PRESHIFT)
            ->where('shift_id', $entry->shift_id)
            ->where('user_id', $entry->user_id)
            ->value('answer') === Notification::CANT;
        if ($entry->freeloaded_by || !$pending) {
            $this->api->edit($chatId, $messageId, 'Already decided.');
            return;
        }

        $shift = $entry->shift;
        $volunteer = $entry->user;
        $reason = Roster::cancelReason($entry);
        $what = e($shift->title) . ' · ' . $this->format->shiftTime($shift);

        if ($excused) {
            // The reason stays out of the log, it may be about someone's health
            Roster::unassign($entry, 'excused by ' . $lead->name);
            Notification::answer($entry, Notification::EXCUSED);
            $done = sprintf('✅ %s excused %s, they are off %s.', $this->format->user($lead), $this->format->user($volunteer), $what);
            $toVolunteer = sprintf('✅ You were excused from %s. You are off the shift.', $what);
        } else {
            Roster::markNoShow($entry, $lead, 'Not excused' . ($reason ? ': ' . $reason : ''));
            Notification::answer($entry, Notification::UNEXCUSED);
            $done = sprintf('❌ %s marked %s as no-show for %s.', $this->format->user($lead), $this->format->user($volunteer), $what);
            $toVolunteer = sprintf('❌ Your lead did not excuse you from %s. You are marked as a no-show for this shift.', $what);
        }

        if ($rollCall) {
            [$text, $buttons] = $this->rollCallMessage($shift, $entry->angel_type_id);
            $this->api->edit($chatId, $messageId, $text, $buttons);
        } else {
            // The gap alert skips this chat, so the button to fill it comes here
            $this->api->edit($chatId, $messageId, $done, $this->replacementButton($shift, $entry->angel_type_id));
        }

        if ($target = TelegramAccount::chatOf($volunteer)) {
            $this->api->send($target, $toVolunteer);
        }
        $this->alertGap($shift, $entry->angel_type_id, $done, exceptChatId: $chatId);
    }

    public function markHere(int|string $chatId, ?int $messageId, User $lead, int $entryId): void
    {
        $entry = ShiftEntry::query()->with('shift')->find($entryId);
        if (!$entry || !$this->canRollCall($lead, $entry)) {
            return;
        }

        Checkin::record($entry->user_id, $entry->shift->start);
        $answer = Notification::query()
            ->where('kind', Notification::PRESHIFT)
            ->where('shift_id', $entry->shift_id)
            ->where('user_id', $entry->user_id)
            ->value('answer');
        if (in_array($answer, [Notification::MOVED, Notification::CANT])) {
            Notification::answer($entry, Notification::HERE);
        }

        [$text, $buttons] = $this->rollCallMessage($entry->shift, $entry->angel_type_id);
        $this->api->edit($chatId, $messageId, $text, $buttons);
    }

    public function markNoShow(int|string $chatId, ?int $messageId, User $lead, int $entryId): void
    {
        $entry = ShiftEntry::query()->with(['shift', 'user'])->find($entryId);
        if (!$entry || !$this->canRollCall($lead, $entry)) {
            return;
        }

        if (!$entry->freeloaded_by) {
            $reason = Roster::cancelReason($entry);
            Roster::markNoShow($entry, $lead, $reason ? 'Not excused: ' . $reason : null);
            $this->alertGap(
                $entry->shift,
                $entry->angel_type_id,
                sprintf('❌ %s did not show up (reported by %s).', $this->format->user($entry->user), $this->format->user($lead)),
                exceptChatId: $chatId
            );
        }

        [$text, $buttons] = $this->rollCallMessage($entry->shift, $entry->angel_type_id);
        $this->api->edit($chatId, $messageId, $text, $buttons);
    }

    /**
     * @param int|null $messageId the message whose button was tapped
     * @param bool     $forOneGap whether that button stands for one gap: picking someone removes it
     */
    public function showReplacements(
        int|string $chatId,
        User $user,
        int $shiftId,
        int $angelTypeId,
        ?int $messageId = null,
        bool $forOneGap = false,
        bool $inPlace = false
    ): void {
        $shift = Shift::query()->find($shiftId);
        $area = AngelType::query()->find($angelTypeId);
        if (!$shift || !$area || !$this->canLead($user, $angelTypeId)) {
            return;
        }

        $inPlace = $inPlace && $messageId;
        // From the roll call, the list replaces it in the same message, with a way back
        $reply = fn(string $text, array $buttons = []) => $inPlace
            ? $this->api->edit($chatId, $messageId, $text, [
                ...$buttons,
                [['text' => '← Back to roll call', 'callback_data' => sprintf('rc:%d:%d', $shiftId, $angelTypeId)]],
            ])
            : $this->api->send($chatId, $text, $buttons);

        $standby = Roster::standbyArea();
        if ($shift->end <= Carbon::now()) {
            $reply(sprintf('This shift is over (%s %s).', e($area->name), $this->format->shiftLabel($shift)));
            return;
        }
        if ($standby?->id === $angelTypeId) {
            $reply(sprintf('%s isn\'t refilled, it gets smaller as people step in elsewhere.', e($standby->name)));
            return;
        }
        if (!$standby && Roster::standbyName() !== '') {
            $reply(sprintf(
                'The standby area "%s" in Settings > Telegram doesn\'t exist. Ask coordination to fix the name.',
                e(Roster::standbyName())
            ));
            return;
        }

        $staffing = Roster::staffing($shift, $angelTypeId);
        $candidates = Roster::replacements($shift, $angelTypeId)->take(15);

        $lines = [sprintf(
            '🔁 <b>Replacement for %s · %s</b> (%d/%d)',
            e($area->name),
            $this->format->shiftLabel($shift),
            $staffing['filled'],
            $staffing['needed']
        )];

        if ($staffing['filled'] >= $staffing['needed'] && $inPlace) {
            $this->showRollCall($chatId, $messageId, $user, $shiftId, $angelTypeId);
            return;
        }
        if ($staffing['filled'] >= $staffing['needed']) {
            // The slot alert's button has done its job; other messages keep their buttons
            if ($messageId && $forOneGap) {
                $this->api->call('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId]);
            }
            $this->api->send($chatId, $lines[0] . "\n\n✅ This shift is fully staffed again.");
            return;
        }

        if ($candidates->isEmpty()) {
            $lines[] = '';
            $lines[] = $standby
                ? 'Nobody is on standby right now (marked arrived and not busy). Ask coordination.'
                : sprintf('Nobody from %s is free. Ask coordination for someone from another area.', e($area->name));
            $reply(implode("\n", $lines));
            return;
        }

        $lines[] = $standby
            ? sprintf('On %s right now, on site first. Picking someone moves them here:', e($standby->name))
            : 'Free team members, on site first:';
        $buttons = [];
        foreach ($candidates as $candidate) {
            $buttons[] = [[
                'text'          => ($candidate->checked_in ? '📍 ' : '') . $this->format->plainName($candidate),
                'callback_data' => sprintf('asg:%d:%d:%d', $shiftId, $angelTypeId, $candidate->id)
                    . (($forOneGap || $inPlace) && $messageId ? ':' . $messageId : ''),
            ]];
        }
        $lines[] = '📍 = checked in today';

        $reply(implode("\n", $lines), $buttons);
    }

    /**
     * The roll call again, in the message it was turned into the replacement list in
     */
    public function showRollCall(int|string $chatId, int $messageId, User $user, int $shiftId, int $angelTypeId, string $note = ''): void
    {
        $shift = Shift::query()->find($shiftId);
        if (!$shift || !$this->canLead($user, $angelTypeId)) {
            return;
        }

        [$text, $buttons] = $this->rollCallMessage($shift, $angelTypeId);
        $this->api->edit($chatId, $messageId, ($note !== '' ? $note . "\n\n" : '') . $text, $buttons);
    }

    public function assignReplacement(
        int|string $chatId,
        ?int $messageId,
        User $by,
        int $shiftId,
        int $angelTypeId,
        int $userId,
        ?int $gapMessageId = null
    ): void {
        $shift = Shift::query()->find($shiftId);
        $user = User::query()->find($userId);
        if (!$shift || !$user || !$this->canLead($by, $angelTypeId)) {
            return;
        }

        $standbyEntry = Roster::standbyFor($shift, $angelTypeId)->firstWhere('user_id', $user->id);
        $fromStandby = (bool) $standbyEntry;
        // Picked in the roll call itself (the list replaced it): the result is the roll call again
        $inPlace = $gapMessageId && $gapMessageId === $messageId;
        $entry = Roster::assignReplacement($user, $shift, $angelTypeId, $by->name . ' via Telegram');
        if ($entry === Roster::ALREADY_ON_SHIFT) {
            // A double tap: the first one already turned the list into the result
            return;
        }
        if (is_string($entry)) {
            $this->api->edit($chatId, $messageId, sprintf('Could not add %s: %s.', $this->format->user($user), e($entry)), $inPlace
                ? [[['text' => '← Back to roll call', 'callback_data' => sprintf('rc:%d:%d', $shiftId, $angelTypeId)]]]
                : []);
            return;
        }
        $staffing = Roster::staffing($shift, $angelTypeId);
        // Checked in for the day, but not seen at this shift yet: the roll call asks the lead. For a
        // later shift they get the usual reminder instead (MOVED counts as answered)
        if ($shift->start <= Carbon::now()->addMinutes(Scheduler::PRESHIFT_MINUTES)) {
            Notification::answer($entry, Notification::MOVED);
        }

        $target = TelegramAccount::chatOf($user);
        $told = $target && $this->api->send($target, sprintf(
            "🙋 %s by %s:\n\n%s\n\nPlease head there now / on time. Thank you!",
            $fromStandby ? "You've been moved from standby to a shift" : "You've been added to a shift",
            $this->format->user($by),
            $this->format->entry($entry, withLeads: true)
        )) !== null;

        $told = $told ? 'I sent them the details.' : 'I couldn\'t reach them on Telegram, please tell them directly.';
        if ($inPlace) {
            // They're on the roll call now, as "not seen here yet"
            $this->showRollCall($chatId, $messageId, $by, $shiftId, $angelTypeId, sprintf(
                '✅ %s is now on the shift%s. %s',
                $this->format->user($user),
                $fromStandby ? ', moved from standby' : '',
                $told
            ));
        } else {
            $this->confirmReplacement($chatId, $messageId, $user, $entry, $staffing, $fromStandby, $told, $gapMessageId);
        }
        $this->tellAboutReplacement($chatId, $user, $shift, $angelTypeId, $staffing, $standbyEntry);
    }

    /**
     * The picker becomes the result, with the next steps
     */
    protected function confirmReplacement(
        int|string $chatId,
        ?int $messageId,
        User $user,
        ShiftEntry $entry,
        array $staffing,
        bool $fromStandby,
        string $told,
        ?int $gapMessageId
    ): void {
        $shift = $entry->shift;
        $angelTypeId = $entry->angel_type_id;
        // The gap's Find replacement button has done its job
        if ($gapMessageId) {
            $this->api->call('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $gapMessageId]);
        }

        // Once the roll call is out, it's over for them: here or no-show is decided right here
        $buttons = Scheduler::rollCallStarted($shift) ? [[
            ['text' => '✅ ' . $this->format->plainName($user) . ' is here', 'callback_data' => 'here:' . $entry->id],
            ['text' => '❌ No-show', 'callback_data' => 'ns:' . $entry->id],
        ]] : [];
        // Still short: the next replacement is one tap away, marking this one can wait
        if ($staffing['filled'] < $staffing['needed']) {
            $buttons[] = [[
                'text'          => sprintf('🔁 Find replacement (%d short)', $staffing['needed'] - $staffing['filled']),
                'callback_data' => 'rep:' . $shift->id . ':' . $angelTypeId,
            ]];
        }
        $this->api->edit($chatId, $messageId, sprintf(
            '✅ %s is now on %s (%d/%d)%s. %s',
            $this->format->user($user),
            $this->format->shiftLabel($shift),
            $staffing['filled'],
            $staffing['needed'],
            $fromStandby ? ', moved from standby' : '',
            $told
        ), $buttons);
    }

    /**
     * The area's groups and, for a move from standby, coordination; not the chat it was picked in
     */
    protected function tellAboutReplacement(
        int|string $chatId,
        User $user,
        Shift $shift,
        int $angelTypeId,
        array $staffing,
        ?ShiftEntry $standbyEntry
    ): void {
        $chats = Chat::forArea($angelTypeId)->pluck('chat_id');
        $note = '';
        // Coordination keeps track of who is left on standby
        if ($standbyEntry) {
            $chats = $chats->merge(Chat::forArea(null)->pluck('chat_id'));
            $note = sprintf(
                ', moved from standby (%d left on %s)',
                Roster::staffing($standbyEntry->shift, $standbyEntry->angel_type_id)['filled'],
                e($standbyEntry->shift->title)
            );
        }
        foreach ($chats->unique()->reject(fn($chat) => (string) $chat === (string) $chatId) as $chat) {
            $this->api->send($chat, sprintf(
                '✅ %s filled a slot on %s %s (%d/%d)%s.',
                $this->format->user($user),
                e($shift->title),
                $this->format->shiftTime($shift),
                $staffing['filled'],
                $staffing['needed'],
                $note
            ));
        }
    }

    /**
     * Tells the shift's leads, the area group and coordination that a slot opened up
     */
    protected function alertGap(Shift $shift, int $angelTypeId, string $reason, int|string|null $exceptChatId = null): void
    {
        $staffing = Roster::staffing($shift, $angelTypeId);
        // Standby isn't refilled, it shrinks as people step in elsewhere
        if ($staffing['filled'] >= $staffing['needed'] || $angelTypeId === Roster::standbyArea()?->id) {
            return;
        }

        $area = AngelType::query()->find($angelTypeId);
        $text = sprintf(
            "%s\n\n⚠️ <b>%s · %s</b> now has %d/%d.",
            $reason,
            e($area?->name ?? 'This shift'),
            $this->format->shiftLabel($shift),
            $staffing['filled'],
            $staffing['needed']
        );
        $buttons = [[['text' => '🔁 Find replacement', 'callback_data' => 'rep:' . $shift->id . ':' . $angelTypeId]]];

        // The area's leads group (any lead there can find a replacement), or the shift's leads
        // directly if the area has none; coordination always
        $areaGroups = Chat::forArea($angelTypeId)->pluck('chat_id');
        $chats = $areaGroups->isNotEmpty()
            ? $areaGroups->all()
            : Roster::leadsOf($shift, $angelTypeId)->map(fn(User $lead) => TelegramAccount::chatOf($lead))->all();
        $chats = [...$chats, ...Chat::forArea(null)->pluck('chat_id')->all()];

        foreach (array_unique(array_filter($chats)) as $chat) {
            if ((string) $chat !== (string) $exceptChatId) {
                $this->api->send($chat, $text, $buttons);
            }
        }
    }

    /**
     * Sends to the shift's leads directly, or to coordination if none of them can be reached
     *
     * @return bool whether a lead got it
     */
    public function sendToLeads(Shift $shift, int $angelTypeId, string $text, array $buttons = [], ?User $except = null): bool
    {
        $reached = false;
        foreach (Roster::leadsOf($shift, $angelTypeId) as $lead) {
            if ($lead->id !== $except?->id && ($chatId = TelegramAccount::chatOf($lead))) {
                $reached = $this->api->send($chatId, $text, $buttons) !== null || $reached;
            }
        }

        if (!$reached) {
            foreach (Chat::forArea(null) as $chat) {
                $this->api->send($chat->chat_id, $this->toCoordinationNote($shift, $angelTypeId, $except) . "\n\n" . $text, $buttons);
            }
        }

        return $reached;
    }

    /**
     * Why something for the shift's lead went to coordination instead
     */
    protected function toCoordinationNote(Shift $shift, int $angelTypeId, ?User $except = null): string
    {
        $leads = Roster::leadsOf($shift, $angelTypeId);

        return match (true) {
            $leads->isEmpty()
                => '⚠️ This shift has no lead, so this goes to coordination.',
            $except && $leads->every(fn(User $lead) => $lead->id === $except->id)
                => sprintf('⚠️ %s is the lead of this shift, so this goes to coordination.', $this->format->user($except)),
            default
                => '⚠️ The lead of this shift couldn\'t be reached on Telegram, so this goes to coordination.',
        };
    }

    protected function canLead(User $user, int $angelTypeId): bool
    {
        return Roster::isCoordinator($user) || Roster::isSupporter($user->id, $angelTypeId);
    }

    /**
     * A lead's own entry is for coordination to decide on (leads aren't on their own roll call)
     */
    protected function isOwn(User $lead, ShiftEntry $entry): bool
    {
        return $entry->user_id === $lead->id && !Roster::isCoordinator($lead);
    }

    /**
     * Roll call buttons only work once the roll call is out
     */
    protected function canRollCall(User $lead, ShiftEntry $entry): bool
    {
        return $this->canLead($lead, $entry->angel_type_id)
            && !$this->isOwn($lead, $entry)
            && Scheduler::rollCallStarted($entry->shift);
    }

    /**
     * @return array the Find replacement button if the area is short on the shift (never for standby)
     */
    protected function replacementButton(Shift $shift, int $angelTypeId): array
    {
        $staffing = Roster::staffing($shift, $angelTypeId);
        if ($staffing['filled'] >= $staffing['needed'] || $angelTypeId === Roster::standbyArea()?->id) {
            return [];
        }

        return [[['text' => '🔁 Find replacement', 'callback_data' => 'rep:' . $shift->id . ':' . $angelTypeId]]];
    }
}
