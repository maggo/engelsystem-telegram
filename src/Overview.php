<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;
use Engelsystem\Models\UserAngelType;
use Illuminate\Support\Collection;

/**
 * A day's shifts at a glance, for a screen: per shift and area how many volunteers are needed,
 * assigned, checked in at the desk, marked here in the roll call and marked no-show, grouped by
 * half day (AM starts before noon, as in the import), with the day's totals, and how many of the
 * arrived volunteers checked in that day. The numbers are Attendance's, so leads aren't
 * counted.
 */
class Overview
{
    public const NUMBERS = ['needed', 'filled', 'checked_in', 'here', 'no_shows'];

    public static function data(?string $day): array
    {
        $data = Attendance::data($day);
        $now = $data['now'];
        // The shift's leads as the bot sees them (Roster::leadsOf): supporters of the area on the
        // shift, not as no-show; loaded at once, as the page refreshes itself
        $supporters = UserAngelType::query()->where('supporter', true)->get(['angel_type_id', 'user_id'])
            ->groupBy('angel_type_id')
            ->map(fn(Collection $rows) => $rows->pluck('user_id'));
        $onShift = ShiftEntry::query()
            ->whereIn('shift_id', $data['shifts']->map(fn(array $row) => $row['shift']->id)->unique())
            ->whereNull('freeloaded_by')
            ->get(['shift_id', 'user_id'])
            ->groupBy('shift_id')
            ->map(fn(Collection $rows) => $rows->pluck('user_id'));
        $leadIds = fn(array $row) => ($supporters->get($row['angel_type']->id) ?? collect())
            ->intersect($onShift->get($row['shift']->id) ?? collect())
            ->unique()
            ->values();
        $users = User::query()
            ->with('personalData')
            ->whereIn('id', $data['shifts']->flatMap($leadIds)->unique())
            ->get()
            ->keyBy('id');
        $rows = $data['shifts']->map(fn(array $row) => [
            ...$row,
            'leads' => $leadIds($row)->map(fn($id) => $users->get((int) $id))->filter()->values(),
        ]);
        $handles = TelegramAccount::query()
            ->whereIn('user_id', $rows->flatMap(fn(array $row) => $row['leads']->pluck('id'))->unique())
            ->pluck('username', 'user_id');
        $rows = $rows->map(fn(array $row) => [
            ...$row,
            'leads' => $row['leads']
                ->sortBy(fn(User $lead) => mb_strtolower($lead->displayName))
                ->map(fn(User $lead) => ['user' => $lead, 'handle' => $handles->get($lead->id)])
                ->values(),
        ]);

        $slots = $rows
            ->groupBy(fn(array $row) => $row['shift']->start->hour < 12 ? 'AM' : 'PM')
            ->sortKeys()
            ->map(fn(Collection $rows, string $half) => [
                'name'   => $half,
                'state'  => self::state($rows, $now),
                'totals' => self::totals($rows),
                'rows'   => $rows->sortBy(fn(array $row) => [$row['angel_type']->name, $row['shift']->title])->values(),
            ])
            ->values();

        // The volunteers in Engelsystem: arrived (onboarded), in an area (so not the admin account)
        // and not a lead; how many of them checked in that day, also without a shift
        $volunteers = User::query()
            ->whereNotIn('id', Roster::leadIds())
            ->whereIn('id', UserAngelType::query()->select('user_id'))
            ->whereHas('state', fn($query) => $query->whereArrived(true))
            ->pluck('id');
        $everyone = [
            'checked_in' => Checkin::query()->where('day', $data['day']->toDateString())
                ->whereIn('user_id', $volunteers)->count(),
            'volunteers' => $volunteers->count(),
        ];

        return [
            'now'      => $now,
            'day'      => $data['day'],
            'days'     => $data['days'],
            'totals'   => self::totals($rows),
            'everyone' => $everyone,
            'slots'    => $slots,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected static function totals(Collection $rows): array
    {
        return collect(self::NUMBERS)->mapWithKeys(fn(string $key) => [$key => (int) $rows->sum($key)])->all();
    }

    /**
     * over (every shift of the half day is), running (one has started) or upcoming
     */
    protected static function state(Collection $rows, Carbon $now): string
    {
        return match (true) {
            $rows->every(fn(array $row) => $row['shift']->end <= $now)      => 'over',
            $rows->contains(fn(array $row) => $row['shift']->start <= $now) => 'running',
            default                                                         => 'upcoming',
        };
    }
}
