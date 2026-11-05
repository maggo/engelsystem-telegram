<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Every shift of a half day on one page, to move anyone on an area's shift to the standby shift
 * of the same half day, or anyone on standby to any area's shift, one person at a time
 *
 * A move is the same as on the swap page (see Swap::run()): the person joins the area they move
 * to, and the bot tells them, the leads and coordination.
 */
class StandbyBoard
{
    public function __construct(protected Swap $swap)
    {
    }

    /**
     * The half day's standby shifts and area shifts with their people; leads aren't listed
     */
    public static function data(?string $day, ?string $half): array
    {
        ['days' => $days, 'day' => $day, 'half' => $half] = Swap::halfDay($day, $half);
        $standby = Roster::standbyArea();
        $sides = $day ? Swap::sides(Carbon::parse($day), $half) : collect();
        $today = $day && Carbon::parse($day)->isToday();

        $columns = $sides->map(function (array $side) use ($today): array {
            $column = Swap::people($side, $today);
            $column['key'] = $side['key'];
            $column['lead_names'] = collect($column['people'])->where('lead', true)->pluck('name')->all();
            $column['people'] = array_values(array_filter($column['people'], fn(array $person) => !$person['lead']));

            return $column;
        });
        $isStandby = fn(array $side) => $standby && $side['area']->id === $standby->id;
        $standbyKeys = $sides->filter($isStandby)->keys();
        $areaKeys = $sides->reject($isStandby)->keys();

        return [
            'days'          => $days,
            'day'           => $day,
            'half'          => $half,
            'standby_name'  => Roster::standbyName(),
            'standby_area'  => $standby?->name,
            'standby'       => $standbyKeys->map(fn(string $key) => $columns[$key])->values()->all(),
            'areas'         => $areaKeys->map(fn(string $key) => $columns[$key])->values()->all(),
            // Where people can go: from standby to any area shift, from an area shift to standby,
            // with how full each is, the same labels as on the swap page; shifts that are over can't
            // take anyone
            'to_area'       => self::targets($areaKeys, $columns),
            'to_standby'    => self::targets($standbyKeys, $columns),
        ];
    }

    /**
     * Moves one person's entry to the picked shift; one of the two must be the standby
     *
     * @return array see Swap::run()
     * @throws InvalidArgumentException when nothing moved, with why
     */
    public function move(mixed $entryId, mixed $to, User $by): array
    {
        if (!is_string($entryId) || !ctype_digit($entryId) || strlen($entryId) > 10 || !is_string($to)) {
            throw new InvalidArgumentException('Pick the person and where they go again, nothing was moved.');
        }
        if ($to === '') {
            throw new InvalidArgumentException('Pick the shift they go to first, nothing was moved.');
        }
        $entry = ShiftEntry::query()->with('user')->find((int) $entryId);
        if (!$entry) {
            throw new InvalidArgumentException(
                'That person isn\'t on the shift any more (someone moved them), nothing was moved.'
            );
        }
        $standby = Roster::standbyArea();
        if (!$standby) {
            throw new InvalidArgumentException(sprintf(
                'There is no standby area (Settings > Telegram says "%s"), nothing was moved.',
                Roster::standbyName()
            ));
        }
        if (Roster::leadIds()->contains($entry->user_id)) {
            throw new InvalidArgumentException(sprintf(
                '%s is a lead, leads aren\'t moved here, nothing was moved.',
                $entry->user?->displayName ?? 'That person'
            ));
        }
        $from = $entry->shift_id . '-' . $entry->angel_type_id;
        [, $toArea] = Swap::side($to);
        if (((int) $entry->angel_type_id === $standby->id) === ($toArea->id === $standby->id)) {
            throw new InvalidArgumentException(
                'Move people from an area to standby or from standby to an area, nothing was moved.'
            );
        }

        return $this->swap->run($from, $to, [$entry->id], [], $by);
    }

    /**
     * @param Collection<int, string> $keys
     * @param Collection<string, array> $columns
     * @return array<string, string> side key => label, without shifts that are over
     */
    protected static function targets(Collection $keys, Collection $columns): array
    {
        return $keys
            ->reject(fn(string $key) => $columns[$key]['over'] ?? false)
            ->mapWithKeys(fn(string $key) => [$key => self::label($columns[$key])])
            ->all();
    }

    protected static function label(array $column): string
    {
        return sprintf('%s · %d/%d · %s', $column['name'], $column['filled'], $column['needed'], $column['time']);
    }
}
