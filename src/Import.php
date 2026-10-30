<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Factories\User as UserFactory;
use Engelsystem\Http\Exceptions\ValidationException;
use Engelsystem\Models\AngelType;
use Engelsystem\Models\Location;
use Engelsystem\Models\Shifts\NeededAngelType;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftType;
use Engelsystem\Models\User\User;
use Engelsystem\Models\UserAngelType;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * One-time import of the final volunteer list: creates the accounts with areas, lead role,
 * Telegram handle and shifts. Accounts that already exist (e.g. leads added ahead) only get their
 * areas, lead role and shifts added; nothing is changed or taken away. Afterwards everything is
 * managed in Engelsystem.
 *
 * CSV columns: handle, name, area, shifts, lead
 * - area: the role name, e.g. "Info Desk"
 * - shifts: "2027-08-17 AM; 2027-08-18 PM" (or "08-17 AM"), matched to the area's shift starting that
 *   day; a shift in another area names it: "2027-08-19 AM Kitchen". They become members of that area too.
 * - lead: "yes" makes them a supporter (lead) of the area in the area column; a list names the areas
 *   they lead instead, e.g. "Info Desk; Kitchen". Empty or "no": not a lead
 */
class Import
{
    protected const USERNAME_LENGTH = 24;

    /**
     * @return array{created: int, existing: string[], problems: string[]}
     */
    public static function run(string $csv, User $by): array
    {
        // A few hundred rows take longer than a page view may
        set_time_limit(AfterResponse::TIME_LIMIT_SECONDS);
        $areas = AngelType::query()->get()->keyBy(fn(AngelType $type) => mb_strtolower($type->name));
        // No welcome e-mail per account: the addresses are placeholders, volunteers get the bot
        app('events.dispatcher')->forget('user.created');
        $result = ['created' => 0, 'existing' => [], 'problems' => []];

        foreach (preg_split('/\R/', trim($csv)) as $number => $line) {
            $columns = array_map('trim', str_getcsv($line, ',', '"', ''));
            $handle = TelegramAccount::normalize($columns[0] ?? '');
            if ($handle === '' || in_array($handle, ['handle', 'telegram', 'username'])) {
                continue;
            }
            $problem = function (string $text) use (&$result, $number, $handle): void {
                $result['problems'][] = sprintf('Line %d (@%s): %s', $number + 1, $handle, $text);
            };

            $areaName = mb_strtolower($columns[2] ?? '');
            $area = $areaName !== '' ? $areas->get($areaName) : null;
            if ($areaName !== '' && !$area) {
                $problem(sprintf('unknown area "%s", not imported', $columns[2]));
                continue;
            }
            // "yes" leads the area column; a list names the areas they lead, e.g. "Kitchen; Bar"
            $leadOf = [];
            $leadValue = $columns[4] ?? '';
            if (self::isYes($leadValue)) {
                if (!$area) {
                    $problem('a lead needs an area, not imported');
                    continue;
                }
                $leadOf = [$area];
            } elseif ($leadValue !== '' && !self::isNo($leadValue)) {
                foreach (preg_split('/[;|]/', $leadValue) as $name) {
                    $leadOf[] = $areas->get(mb_strtolower(trim($name))) ?? trim($name);
                }
                $unknown = array_filter($leadOf, 'is_string');
                if ($unknown) {
                    $problem(sprintf('unknown area "%s" in lead, not imported', implode('", "', $unknown)));
                    continue;
                }
            }

            try {
                $slots = self::parseShifts($columns[3] ?? '', $area, $areas);
                $shifts = array_map(fn(array $slot) => [self::findShift($slot['area'], $slot), $slot['area']], $slots);
            } catch (InvalidArgumentException $e) {
                $problem($e->getMessage() . ', not imported');
                continue;
            }

            // Someone added ahead (e.g. the leads) only gets areas and shifts: their name, handle and
            // Telegram link stay as they are
            $user = self::existing($handle);
            $existing = (bool) $user;
            if (!$user) {
                try {
                    $user = self::createAccount($handle, $columns[1] ?? '', $by);
                } catch (Throwable $e) {
                    $problem('account could not be created: ' . self::describe($e));
                    continue;
                }
                $result['created']++;
                if (mb_strtolower($user->name) !== $handle) {
                    $problem(sprintf('created as "%s", as the handle doesn\'t fit a username; the bot still knows @%s', $user->name, $handle));
                }
            }

            // Members of their area, the areas they lead and every area they have a shift in, as
            // Engelsystem only puts members on its shifts
            foreach ($leadOf as $led) {
                self::join($user, $led, true, $by);
            }
            foreach (collect([$area, ...collect($shifts)->pluck(1)])->filter()->unique('id') as $member) {
                self::join($user, $member, false, $by);
            }

            $added = 0;
            foreach ($shifts as [$shift, $shiftArea]) {
                $conflict = Roster::conflict($user, $shift, $shiftArea->id);
                if ($conflict === Roster::ALREADY_ON_SHIFT) {
                    continue;
                }
                if ($conflict) {
                    $problem(sprintf('not put on %s %s: %s', $shift->title, $shift->start->format('D M j'), $conflict));
                    continue;
                }
                Roster::assign($user, $shift, $shiftArea->id, 'import by ' . $by->name);
                $added++;
            }

            if ($existing) {
                $result['existing'][] = sprintf(
                    'Line %d (@%s): existing account "%s"%s, %d shift(s) added',
                    $number + 1,
                    $handle,
                    $user->name,
                    TelegramAccount::query()->whereKey($user->id)->value('username') === $handle ? '' : ', not set to connect as @' . $handle,
                    $added
                );
            }
        }

        Roster::log(sprintf(
            'Import by %s: %d account(s) created, %d already existed, %d problem(s)',
            $by->name,
            $result['created'],
            count($result['existing']),
            count($result['problems'])
        ));

        return $result;
    }

    /**
     * Creates the shifts the volunteer list is matched to, so they don't have to be made by hand.
     * CSV columns: title, area, location, start, end, needed, e.g.
     * "Info Desk AM,Info Desk,Info Desk,2027-08-17 08:00,2027-08-17 14:00,8". The location and a
     * shift type named after the area are created if missing. A shift that already exists (same
     * title, start, end and location) is reused: a missing area is added, a lower need is raised.
     *
     * @return array{created: int, merged: string[], problems: string[]}
     */
    public static function shifts(string $csv, User $by): array
    {
        set_time_limit(AfterResponse::TIME_LIMIT_SECONDS);
        $areas = AngelType::query()->get()->keyBy(fn(AngelType $type) => mb_strtolower($type->name));
        $result = ['created' => 0, 'merged' => [], 'problems' => []];

        foreach (preg_split('/\R/', trim($csv)) as $number => $line) {
            $columns = array_map('trim', str_getcsv($line, ',', '"', ''));
            if (($columns[0] ?? '') === '' || mb_strtolower($columns[0]) === 'title') {
                continue;
            }
            [$title, $areaName, $locationName, $start, $end, $needed] = array_pad($columns, 6, '');
            $problem = function (string $text) use (&$result, $number, $title): void {
                $result['problems'][] = sprintf('Line %d (%s): %s, not imported', $number + 1, $title, $text);
            };

            $area = $areas->get(mb_strtolower($areaName));
            if (!$area) {
                $problem(sprintf('unknown area "%s"', $areaName));
                continue;
            }
            if ($locationName === '') {
                $problem('no location');
                continue;
            }
            try {
                // "!": seconds are 0, not taken from the current time
                $start = Carbon::createFromFormat('!Y-m-d H:i', $start);
                $end = Carbon::createFromFormat('!Y-m-d H:i', $end);
            } catch (Throwable) {
                $start = $end = null;
            }
            if (!$start || !$end || $end <= $start) {
                $problem('start and end must look like "2027-08-17 08:00", end after start');
                continue;
            }
            if (!ctype_digit($needed) || (int) $needed < 1) {
                $problem(sprintf('needed must be a number of people, not "%s"', $needed));
                continue;
            }

            $location = Location::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($locationName)])->first()
                ?? Location::query()->create(['name' => $locationName]);
            $shift = Shift::query()
                ->where(['title' => $title, 'location_id' => $location->id])
                ->where('start', $start)
                ->where('end', $end)
                ->first();

            if (!$shift) {
                $type = ShiftType::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($area->name)])->first()
                    ?? ShiftType::query()->create(['name' => $area->name, 'description' => '']);
                $shift = Shift::query()->create([
                    'title'         => $title,
                    'description'   => '',
                    'start'         => $start,
                    'end'           => $end,
                    'shift_type_id' => $type->id,
                    'location_id'   => $location->id,
                    'created_by'    => $by->id,
                ]);
                NeededAngelType::query()->create(['shift_id' => $shift->id, 'angel_type_id' => $area->id, 'count' => (int) $needed]);
                $result['created']++;
                continue;
            }

            $need = NeededAngelType::query()->firstOrNew(['shift_id' => $shift->id, 'angel_type_id' => $area->id]);
            $before = $need->exists ? $need->count : 0;
            if ($before < (int) $needed) {
                $need->count = (int) $needed;
                $need->save();
            }
            $result['merged'][] = sprintf(
                'Line %d (%s): the shift already existed, %s',
                $number + 1,
                $title,
                $before < (int) $needed ? sprintf('%s needs %d now (was %d)', $area->name, (int) $needed, $before) : 'left unchanged'
            );
        }

        Roster::log(sprintf(
            'Shift import by %s: %d shift(s) created, %d already existed, %d problem(s)',
            $by->name,
            $result['created'],
            count($result['merged']),
            count($result['problems'])
        ));

        return $result;
    }

    /**
     * The account for a handle: the one an admin set it for, else the Engelsystem username (people
     * added in Engelsystem); a username match doesn't connect them, see TelegramAccount
     */
    protected static function existing(string $handle): ?User
    {
        return TelegramAccount::findByHandle($handle)?->user
            ?? User::query()->whereRaw('LOWER(name) = ?', [$handle])->first();
    }

    /**
     * Adds the area membership, confirmed; never takes a lead role away
     */
    protected static function join(User $user, AngelType $area, bool $lead, User $by): void
    {
        $membership = UserAngelType::query()->firstOrNew(['user_id' => $user->id, 'angel_type_id' => $area->id]);
        $changed = !$membership->exists || !$membership->confirm_user_id || ($lead && !$membership->supporter);
        $membership->confirm_user_id ??= $by->id;
        $membership->supporter = $membership->supporter || $lead;
        $membership->save();
        if ($changed) {
            // Engelsystem's own wording, as when an admin adds someone to an angel type
            Roster::log(sprintf(
                'User %s added to angel type %s, confirmed: true, supporter: %s.',
                $user->name,
                $area->name,
                $membership->supporter ? 'true' : 'false'
            ));
        }
    }

    /**
     * Creates the account through Engelsystem's own registration, without password or email
     */
    protected static function createAccount(string $handle, string $name, User $by): User
    {
        /** @var Connection $db */
        $db = app(Connection::class);
        $session = app('session');
        $session->set('oauth2_enable_password', false);
        [$firstName, $lastName] = array_pad(preg_split('/\s+/', trim($name), 2), 2, null);

        $db->beginTransaction();
        try {
            /** @var UserFactory $factory */
            $factory = app(UserFactory::class);
            $user = $factory->createFromData([
                'username'             => self::availableUsername($handle),
                // Placeholder on the reserved .invalid TLD, volunteers are reached via Telegram
                'email'                => 'tg-' . $handle . '@telegram.invalid',
                'firstname'            => $firstName ?: null,
                'lastname'             => $lastName ?: null,
                'planned_arrival_date' => $factory->determineBuildUpStartDate()->format('Y-m-d'),
            ]);

            // Registration drops the name unless "full name" is enabled in the config; the bot and
            // the leads need it either way
            $user->personalData->first_name = $firstName ?: null;
            $user->personalData->last_name = $lastName ?: null;
            $user->personalData->save();

            TelegramAccount::query()->create(['user_id' => $user->id, 'username' => $handle]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        } finally {
            $session->remove('oauth2_enable_password');
        }

        return $user;
    }

    /**
     * The handle itself, unless it is longer than Engelsystem allows (24, Telegram allows 32)
     */
    protected static function availableUsername(string $handle): string
    {
        $pattern = (string) config('username_regex');
        $base = $pattern === '' || !preg_match($pattern, $handle) ? $handle : preg_replace('/\W/', '', $handle);
        $base = mb_substr($base, 0, self::USERNAME_LENGTH);

        $username = $base;
        for ($i = 2; User::whereName($username)->exists(); $i++) {
            $username = mb_substr($base, 0, self::USERNAME_LENGTH - strlen((string) $i)) . $i;
        }

        return $username;
    }

    protected static function isYes(string $value): bool
    {
        return in_array(mb_strtolower($value), ['yes', 'y', 'lead', '1', 'true']);
    }

    protected static function isNo(string $value): bool
    {
        return in_array(mb_strtolower($value), ['no', 'n', '0', 'false', '-']);
    }

    protected static function describe(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return implode(', ', array_map(
                fn($field, $errors) => $field . ': ' . implode(', ', $errors),
                array_keys($e->getValidator()->getErrors()),
                $e->getValidator()->getErrors()
            ));
        }

        return $e->getMessage();
    }

    /**
     * @param Collection<string, AngelType> $areas all areas by lower-case name
     * @return array<array{day: Carbon, half: string, area: AngelType}>
     */
    public static function parseShifts(string $shifts, ?AngelType $area, Collection $areas): array
    {
        $slots = [];
        foreach (preg_split('/[;|]/', $shifts) as $slot) {
            $slot = trim($slot);
            if ($slot === '') {
                continue;
            }

            if (!preg_match('/^((?:\d{4}-)?\d{1,2}-\d{1,2})\s*(AM|PM)(?:\s+(.+))?$/i', $slot, $match)) {
                throw new InvalidArgumentException(sprintf('can\'t read shift "%s", use e.g. "2027-08-17 AM"', $slot));
            }
            $slotArea = isset($match[3]) ? $areas->get(mb_strtolower(trim($match[3]))) : $area;
            if (isset($match[3]) && !$slotArea) {
                throw new InvalidArgumentException(sprintf('unknown area "%s" in shift "%s"', trim($match[3]), $slot));
            }
            if (!$slotArea) {
                throw new InvalidArgumentException(sprintf('shift "%s" needs an area', $slot));
            }

            $date = substr_count($match[1], '-') === 1 ? Carbon::now()->year . '-' . $match[1] : $match[1];
            [$year, $month, $day] = array_map('intval', explode('-', $date));
            if (!checkdate($month, $day, $year)) {
                throw new InvalidArgumentException(sprintf('"%s" is not a date', $match[1]));
            }
            $slots[] = ['day' => Carbon::create($year, $month, $day), 'half' => strtoupper($match[2]), 'area' => $slotArea];
        }

        return $slots;
    }

    /**
     * Finds the area's shift on that day; AM starts before noon, PM at or after. There must be
     * exactly one, the list can't say which of several is meant.
     */
    public static function findShift(AngelType $area, array $slot): Shift
    {
        $shifts = Shift::query()
            ->with(['schedule', 'neededAngelTypes'])
            ->whereBetween('start', [$slot['day']->copy()->startOfDay(), $slot['day']->copy()->endOfDay()])
            ->orderBy('start')
            ->get()
            ->filter(fn(Shift $shift) => ($shift->start->hour < 12) === ($slot['half'] === 'AM')
                && Roster::needed($shift)->contains('angel_type_id', $area->id));

        if ($shifts->isEmpty()) {
            throw new InvalidArgumentException(sprintf(
                'no %s %s shift on %s',
                $area->name,
                $slot['half'],
                $slot['day']->format('Y-m-d')
            ));
        }

        if ($shifts->count() > 1) {
            throw new InvalidArgumentException(sprintf(
                '%d %s %s shifts on %s, can\'t tell which one is meant',
                $shifts->count(),
                $area->name,
                $slot['half'],
                $slot['day']->format('Y-m-d')
            ));
        }

        return $shifts->first();
    }
}
