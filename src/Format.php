<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;

/**
 * How people and shifts appear in the bot's messages (Telegram HTML)
 */
class Format
{
    /** Telegram takes up to 4096 characters; this leaves room for a note put in front */
    public const TEXT_BUDGET = 3500;

    /** Telegram allows 100 buttons per message, up to three per person in a roll call */
    public const MAX_LISTED = 33;

    /** @var array<int, string|null> Telegram handles by user id, see loadHandles() */
    protected array $handles = [];

    public function entry(ShiftEntry $entry, bool $withLeads = false): string
    {
        $shift = $entry->shift;
        $location = $shift->location;

        $lines = [sprintf('🗓 <b>%s</b> · %s', e($shift->title), $this->shiftTime($shift))];
        $place = '📍 <b>' . e($location->name) . '</b>';
        if ($location->description) {
            $place .= ' — ' . e($this->cut(trim(strip_tags($location->description)), 300));
        }
        if ($location->map_url) {
            $place .= sprintf(' (<a href="%s">map</a>)', e($location->map_url));
        }
        $lines[] = $place;

        if ($withLeads && ($lead = $this->leadLine($entry))) {
            $lines[] = $lead;
        }

        return implode("\n", $lines);
    }

    /**
     * Who leads the volunteer's shift, with their @handle so the volunteer can message them
     */
    public function leadLine(ShiftEntry $entry): ?string
    {
        $leads = Roster::leadsOf($entry->shift, $entry->angel_type_id)->reject(fn(User $lead) => $lead->id === $entry->user_id);
        if ($leads->isNotEmpty()) {
            return '👤 Lead: ' . $leads->map(fn(User $lead) => $this->user($lead))->implode(', ');
        }

        return Roster::isSupporter($entry->user_id, $entry->angel_type_id) ? '👤 You are the lead of this shift.' : null;
    }

    public function shiftTime(Shift $shift): string
    {
        return $shift->start->format('D M j, H:i') . '–' . $shift->end->format('H:i');
    }

    public function shiftLabel(Shift $shift): string
    {
        return $shift->start->format('D H:i');
    }

    /**
     * Name with a clickable @handle; the Engelsystem username is the handle unless the bot knows
     * a different one (handles too long for a username, or changed since)
     */
    public function user(User $user): string
    {
        if (!array_key_exists($user->id, $this->handles)) {
            $this->loadHandles([$user->id]);
        }

        $handle = $this->handles[$user->id] ?? $user->name;
        $name = $this->plainName($user);

        return mb_strtolower($name) === mb_strtolower($handle)
            ? '@' . e($handle)
            : e($name) . ' @' . e($handle);
    }

    /**
     * Loads the Telegram handles of these users in one query, for user()
     *
     * @param iterable<int> $userIds
     */
    public function loadHandles(iterable $userIds): void
    {
        $missing = array_diff(collect($userIds)->all(), array_keys($this->handles));
        if (!$missing) {
            return;
        }

        $this->handles = array_fill_keys($missing, null) + $this->handles;
        foreach (TelegramAccount::query()->whereIn('user_id', $missing)->pluck('username', 'user_id') as $id => $handle) {
            $this->handles[$id] = $handle;
        }
    }

    /**
     * The bot learned a new handle for the user
     */
    public function setHandle(int $userId, string $handle): void
    {
        $this->handles[$userId] = $handle;
    }

    /**
     * Length as Telegram counts it: the text without the HTML
     */
    public function length(string $html): int
    {
        return mb_strlen(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }

    /**
     * Shortens plain text (before escaping) to at most $max characters
     */
    public function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    /**
     * Adds lines while the message stays within TEXT_BUDGET (minus $reserve for what follows)
     * and MAX_LISTED, then a note how many were left out
     *
     * @param string[] $lines the message so far, extended in place
     * @param string[] $items one line each
     * @return int how many items were added
     */
    public function addWithin(array &$lines, array $items, string $more, int $reserve = 300): int
    {
        $used = $this->length(implode("\n", $lines));
        $added = 0;
        foreach ($items as $item) {
            $length = $this->length($item) + 1;
            if ($added >= self::MAX_LISTED || $used + $length + $reserve > self::TEXT_BUDGET) {
                break;
            }
            $lines[] = $item;
            $used += $length;
            $added++;
        }

        if ($added < count($items)) {
            $lines[] = sprintf($more, count($items) - $added);
        }

        return $added;
    }

    public function plainName(User $user): string
    {
        $name = trim($user->personalData->first_name . ' ' . $user->personalData->last_name);
        return $name !== '' ? $name : $user->name;
    }

    public function shortName(User $user): string
    {
        return $user->personalData->first_name ?: $user->name;
    }
}
