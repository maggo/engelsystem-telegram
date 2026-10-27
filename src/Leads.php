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

        $missing = $entries->filter(fn(ShiftEntry $entry) => !$entry->freeloaded_by
            && !in_array($entry->user_id, $checkedIn));
        $noShows = $entries->filter(fn(ShiftEntry $entry) => (bool) $entry->freeloaded_by);
        $staffing = Roster::staffing($shift, $angelTypeId);

        $lines = [sprintf(
            '📋 <b>Roll call: %s</b> (%s)',
            e($shift->title),
            $this->format->shiftTime($shift)
        )];
        $lines[] = sprintf(
            '%d of %d checked in today · %d needed',
            $entries->count() - $missing->count() - $noShows->count(),
            $entries->count(),
            $staffing['needed']
        );

        $buttons = [];
        if ($missing->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<b>Not checked in:</b>';
            $missing = $missing->values();
            $listed = $this->format->addWithin($lines, $missing->map(fn(ShiftEntry $entry) => '• '
                . $this->format->user($entry->user)
                . match ($answers[$entry->user_id] ?? null) {
                    Notification::LATE => ' — running late',
                    Notification::CANT => ' — can\'t make it: "' . e($this->format->cut(Roster::cancelReason($entry), 80)) . '"',
                    default            => '',
                })->all(), '…and %d more, see the shift in Engelsystem.', 500);
            foreach ($missing->take($listed) as $entry) {
                $answer = $answers[$entry->user_id] ?? null;
                $buttons[] = $answer === Notification::CANT
                    ? [
                        ['text' => '✅ Excuse ' . $this->format->shortName($entry->user), 'callback_data' => 'rexc:' . $entry->id],
                        ['text' => '❌ No-show', 'callback_data' => 'ns:' . $entry->id],
                    ]
                    : [
                        ['text' => '✅ ' . $this->format->shortName($entry->user) . ' is here', 'callback_data' => 'here:' . $entry->id],
                        ['text' => '❌ No-show', 'callback_data' => 'ns:' . $entry->id],
                    ];
            }
        } else {
            $lines[] = '';
            $lines[] = '🎉 Everyone is checked in.';
        }

        if ($noShows->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<b>No-shows:</b> ' . $noShows->take(10)->map(fn($entry) => $this->format->user($entry->user))->implode(', ')
                . ($noShows->count() > 10 ? sprintf(' and %d more', $noShows->count() - 10) : '');
        }

        if ($staffing['filled'] < $staffing['needed']) {
            $lines[] = '';
            $lines[] = sprintf('⚠️ %s is %d short.', e($area?->name), $staffing['needed'] - $staffing['filled']);
            $buttons[] = [['text' => '🔁 Find replacement', 'callback_data' => 'rep:' . $shift->id . ':' . $angelTypeId]];
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
     * off the shift (no goodie penalty), not excused means marked as not present
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
        if ($entry && !$this->canLead($lead, $entry->angel_type_id)) {
            return;
        }

        if (!$entry || $entry->freeloaded_by) {
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
            $toVolunteer = sprintf('Your cancellation for %s was not excused. You are marked as not present for this shift.', $what);
        }

        if ($rollCall) {
            [$text, $buttons] = $this->rollCallMessage($shift, $entry->angel_type_id);
            $this->api->edit($chatId, $messageId, $text, $buttons);
        } else {
            $this->api->edit($chatId, $messageId, $done);
        }

        if ($target = TelegramAccount::chatOf($volunteer)) {
            $this->api->send($target, $toVolunteer);
        }
        $this->alertGap($shift, $entry->angel_type_id, $done, exceptChatId: $chatId);
    }

    public function markHere(int|string $chatId, ?int $messageId, User $lead, int $entryId): void
    {
        $entry = ShiftEntry::query()->with('shift')->find($entryId);
        if (!$entry || !$this->canLead($lead, $entry->angel_type_id)) {
            return;
        }

        Checkin::record($entry->user_id, $entry->shift->start);

        [$text, $buttons] = $this->rollCallMessage($entry->shift, $entry->angel_type_id);
        $this->api->edit($chatId, $messageId, $text, $buttons);
    }

    public function markNoShow(int|string $chatId, ?int $messageId, User $lead, int $entryId): void
    {
        $entry = ShiftEntry::query()->with(['shift', 'user'])->find($entryId);
        if (!$entry || !$this->canLead($lead, $entry->angel_type_id)) {
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

    public function showReplacements(int|string $chatId, User $user, int $shiftId, int $angelTypeId): void
    {
        $shift = Shift::query()->find($shiftId);
        $area = AngelType::query()->find($angelTypeId);
        if (!$shift || !$area || !$this->canLead($user, $angelTypeId)) {
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

        if ($staffing['filled'] >= $staffing['needed']) {
            $this->api->send($chatId, $lines[0] . "\n\n✅ This shift is fully staffed again.");
            return;
        }

        if ($candidates->isEmpty()) {
            $lines[] = '';
            $lines[] = sprintf('Nobody from %s is free. Ask coordination for someone from another area.', e($area->name));
            $this->api->send($chatId, implode("\n", $lines));
            return;
        }

        $lines[] = 'Free team members, on site first:';
        $buttons = [];
        foreach ($candidates as $candidate) {
            $buttons[] = [[
                'text'          => ($candidate->checked_in ? '📍 ' : '') . $this->format->plainName($candidate),
                'callback_data' => sprintf('asg:%d:%d:%d', $shiftId, $angelTypeId, $candidate->id),
            ]];
        }
        $lines[] = '📍 = checked in today';

        $this->api->send($chatId, implode("\n", $lines), $buttons);
    }

    public function assignReplacement(
        int|string $chatId,
        ?int $messageId,
        User $by,
        int $shiftId,
        int $angelTypeId,
        int $userId
    ): void {
        $shift = Shift::query()->find($shiftId);
        $user = User::query()->find($userId);
        if (!$shift || !$user || !$this->canLead($by, $angelTypeId)) {
            return;
        }

        $entry = Roster::assignReplacement($user, $shift, $angelTypeId, $by->name . ' via Telegram');
        if (is_string($entry)) {
            $this->api->edit($chatId, $messageId, sprintf('Could not add %s: %s.', $this->format->user($user), e($entry)));
            return;
        }
        $staffing = Roster::staffing($shift, $angelTypeId);

        $target = TelegramAccount::chatOf($user);
        $told = $target && $this->api->send($target, sprintf(
            "🙋 You've been added to a shift by %s:\n\n%s\n\nPlease head there now / on time. Thank you!",
            $this->format->user($by),
            $this->format->entry($entry, withLeads: true)
        )) !== null;

        $this->api->edit($chatId, $messageId, sprintf(
            '✅ %s is now on %s (%d/%d). %s',
            $this->format->user($user),
            $this->format->shiftLabel($shift),
            $staffing['filled'],
            $staffing['needed'],
            $told ? 'I sent them the details.' : 'I couldn\'t reach them on Telegram, please tell them directly.'
        ));

        foreach (Chat::forArea($angelTypeId) as $chat) {
            $this->api->send($chat->chat_id, sprintf(
                '✅ %s filled a slot on %s %s (%d/%d).',
                $this->format->user($user),
                e($shift->title),
                $this->format->shiftTime($shift),
                $staffing['filled'],
                $staffing['needed']
            ));
        }
    }

    /**
     * Tells the shift's leads, the area group and coordination that a slot opened up
     */
    protected function alertGap(Shift $shift, int $angelTypeId, string $reason, int|string|null $exceptChatId = null): void
    {
        $staffing = Roster::staffing($shift, $angelTypeId);
        if ($staffing['filled'] >= $staffing['needed']) {
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
}
