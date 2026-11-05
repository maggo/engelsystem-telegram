<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Engelsystem\Models\Shifts\NeededAngelType;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\User\User;
use InvalidArgumentException;

/**
 * Every shift's need on one page: one row per area and shift time, one column per day, so
 * coordinators change all numbers at once instead of editing shift by shift
 *
 * The numbers are volunteers, as everywhere in the plugin: Engelsystem's need also holds a slot for
 * each lead on the shift, so saving writes the volunteers plus the area's leads on the shift
 * (no-shows included, see Roster::volunteers()). Shifts that are over can be changed too, as in
 * Engelsystem's shift edit; shifts from a schedule can't (their need comes from the shift type or
 * location).
 */
class Needs
{
    public static function data(): array
    {
        $leadIds = Roster::leadIds();
        $shifts = Shift::query()
            ->with(['schedule', 'neededAngelTypes.angelType', 'shiftEntries'])
            ->orderBy('start')
            ->get();

        $days = [];
        $rows = [];
        foreach ($shifts as $shift) {
            $day = $shift->start->format('Y-m-d');
            $days[$day] = $shift->start->format('D M j');
            foreach (Roster::needed($shift) as $needed) {
                $entries = $shift->shiftEntries->where('angel_type_id', $needed->angel_type_id);
                $staffing = Roster::volunteers($needed->count, $entries, $leadIds);
                $time = $shift->start->format('H:i') . '–' . $shift->end->format('H:i');
                $key = $needed->angelType->name . "\n" . $time;

                $rows[$key] ??= ['area' => $needed->angelType->name, 'time' => $time, 'days' => []];
                $rows[$key]['days'][$day][] = [
                    'shift'      => $shift,
                    'area_id'    => $needed->angel_type_id,
                    'volunteers' => $staffing['needed'],
                    'filled'     => $staffing['filled'],
                    'leads'      => $entries->filter(fn($entry) => $leadIds->contains($entry->user_id))->count(),
                    'editable'   => !$shift->schedule,
                ];
            }
        }
        ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);

        $areas = [];
        foreach ($rows as $row) {
            $areas[$row['area']][] = $row;
        }

        return ['days' => $days, 'areas' => $areas];
    }

    /**
     * Writes the changed numbers in one transaction, all or nothing
     *
     * @param array<int|string, array<int|string, mixed>> $needs  shift id => area id => volunteers
     * @param array<int|string, array<int|string, mixed>> $before shift id => area id => volunteers
     *     shown on the page; only numbers changed there are written, so a coordinator saving the page
     *     doesn't undo what someone else changed meanwhile in another cell
     * @return string[] what changed, one line per shift and area
     */
    public static function save(array $needs, array $before, User $by): array
    {
        $leadIds = Roster::leadIds();
        $changes = [];

        foreach ($needs as $shiftId => $areas) {
            foreach ((array) $areas as $areaId => $value) {
                $value = trim((string) $value);
                $was = trim((string) ($before[$shiftId][$areaId] ?? ''));
                if ($value === $was) {
                    continue;
                }
                if (!ctype_digit($value) || (int) $value > 999) {
                    throw new InvalidArgumentException(sprintf('"%s" is not a number of volunteers.', $value));
                }
                $changes[] = [(int) $shiftId, (int) $areaId, (int) $value, $was];
            }
        }
        if (!$changes) {
            return [];
        }

        return app('db.connection')->transaction(function () use ($changes, $leadIds, $by): array {
            $done = [];
            foreach ($changes as [$shiftId, $areaId, $volunteers, $was]) {
                $shift = Shift::query()->with('schedule')->find($shiftId);
                $need = NeededAngelType::query()
                    ->with('angelType')
                    ->where('shift_id', $shiftId)
                    ->where('angel_type_id', $areaId)
                    ->lockForUpdate()
                    ->first();
                if (!$shift || !$need) {
                    throw new InvalidArgumentException('A shift changed meanwhile, nothing was saved. Please reload.');
                }
                $label = sprintf('%s %s', $need->angelType->name, $shift->start->format('D M j H:i'));
                if ($shift->schedule) {
                    throw new InvalidArgumentException(sprintf('%s can\'t be changed here, nothing was saved.', $label));
                }

                $leads = $shift->shiftEntries()
                    ->where('angel_type_id', $areaId)
                    ->whereIn('user_id', $leadIds)
                    ->count();
                $old = Roster::volunteers($need->count, $shift->shiftEntries()->where('angel_type_id', $areaId)->get(), $leadIds);
                // Someone else saved this cell since the page was loaded
                if ((string) $old['needed'] !== $was) {
                    throw new InvalidArgumentException(sprintf(
                        '%s was changed meanwhile (now %d volunteers), nothing was saved. Please reload.',
                        $label,
                        $old['needed']
                    ));
                }
                $need->count = $volunteers + $leads;
                $need->save();
                $shift->updatedBy()->associate($by);
                $shift->save();

                $line = sprintf(
                    '%s: %d volunteers (was %d)%s',
                    $label,
                    $volunteers,
                    $old['needed'],
                    $leads ? sprintf(', %d in Engelsystem with %s', $need->count, $leads === 1 ? 'the lead' : $leads . ' leads') : ''
                );
                Roster::log(sprintf('%s changed the need of shift %d "%s", %s', $by->name, $shift->id, $shift->title, $line));
                $done[] = $line;
            }

            return $done;
        });
    }
}
