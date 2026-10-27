<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Factories\User as UserFactory;
use Engelsystem\Http\Exceptions\ValidationException;
use Engelsystem\Models\AngelType;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\User\User;
use Engelsystem\Models\UserAngelType;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use Throwable;

/**
 * One-time import of the final volunteer list: creates the accounts with area, lead role,
 * Telegram handle and shifts. Afterwards everything is managed in Engelsystem, so people who
 * already exist are skipped and never changed.
 *
 * CSV columns: handle, name, area, shifts, lead
 * - area: the role name, e.g. "Registration"
 * - shifts: "2026-11-03 AM; 2026-11-04 PM" (or "11-03 AM"), matched to the area's shift starting that day
 * - lead: "yes" makes them a supporter (lead) of the area
 */
class Import
{
    protected const USERNAME_LENGTH = 24;

    /**
     * @return array{created: int, skipped: string[], problems: string[]}
     */
    public static function run(string $csv, User $by): array
    {
        $areas = AngelType::query()->get()->keyBy(fn(AngelType $type) => mb_strtolower($type->name));
        // No welcome e-mail per account: the addresses are placeholders, volunteers get the bot
        app('events.dispatcher')->forget('user.created');
        $result = ['created' => 0, 'skipped' => [], 'problems' => []];

        foreach (preg_split('/\R/', trim($csv)) as $number => $line) {
            $columns = array_map('trim', str_getcsv($line, ',', '"', ''));
            $handle = TelegramAccount::normalize($columns[0] ?? '');
            if ($handle === '' || in_array($handle, ['handle', 'telegram', 'username'])) {
                continue;
            }
            $problem = function (string $text) use (&$result, $number, $handle): void {
                $result['problems'][] = sprintf('Line %d (@%s): %s', $number + 1, $handle, $text);
            };

            if ($reason = self::existing($handle)) {
                $result['skipped'][] = sprintf('Line %d (@%s): %s', $number + 1, $handle, $reason);
                continue;
            }

            $areaName = mb_strtolower($columns[2] ?? '');
            $area = $areaName !== '' ? $areas->get($areaName) : null;
            if ($areaName !== '' && !$area) {
                $problem(sprintf('unknown area "%s", not imported', $columns[2]));
                continue;
            }

            try {
                $shifts = array_map(fn(array $slot) => self::findShift($area, $slot), self::parseShifts($columns[3] ?? '', $area));
            } catch (InvalidArgumentException $e) {
                $problem($e->getMessage() . ', not imported');
                continue;
            }

            try {
                $user = self::createAccount($handle, $columns[1] ?? '', $area, self::isYes($columns[4] ?? ''), $by);
            } catch (Throwable $e) {
                $problem('account could not be created: ' . self::describe($e));
                continue;
            }
            $result['created']++;
            if (mb_strtolower($user->name) !== $handle) {
                $problem(sprintf('created as "%s", as the handle doesn\'t fit a username; the bot still knows @%s', $user->name, $handle));
            }

            foreach ($shifts as $shift) {
                if ($conflict = Roster::conflict($user, $shift, $area->id)) {
                    $problem(sprintf('not put on %s %s: %s', $shift->title, $shift->start->format('D M j'), $conflict));
                    continue;
                }
                Roster::assign($user, $shift, $area->id, 'import by ' . $by->name);
            }
        }

        Roster::log(sprintf(
            'Import by %s: %d account(s) created, %d already existed, %d problem(s)',
            $by->name,
            $result['created'],
            count($result['skipped']),
            count($result['problems'])
        ));

        return $result;
    }

    /**
     * Why a handle is skipped, if an account for it already exists; the Engelsystem username is
     * the handle, so people added in Engelsystem count too
     */
    protected static function existing(string $handle): ?string
    {
        if ($account = TelegramAccount::findByHandle($handle)) {
            return sprintf(
                'already has the account "%s"%s',
                $account->user->name,
                $account->chat_id || $account->telegram_id ? ', connected to Telegram' : ''
            );
        }

        $user = User::query()->whereRaw('LOWER(name) = ?', [$handle])->first();
        return $user ? sprintf('an Engelsystem account "%s" already exists', $user->name) : null;
    }

    /**
     * Creates the account through Engelsystem's own registration, without password or email
     */
    protected static function createAccount(string $handle, string $name, ?AngelType $area, bool $lead, User $by): User
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

            if ($area) {
                UserAngelType::query()->create([
                    'user_id'         => $user->id,
                    'angel_type_id'   => $area->id,
                    'confirm_user_id' => $by->id,
                    'supporter'       => $lead,
                ]);
            }

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
     * @return array<array{day: Carbon, half: string}>
     */
    public static function parseShifts(string $shifts, ?AngelType $area): array
    {
        $slots = [];
        foreach (preg_split('/[;|]/', $shifts) as $slot) {
            $slot = trim($slot);
            if ($slot === '') {
                continue;
            }

            if (!preg_match('/^((?:\d{4}-)?\d{1,2}-\d{1,2})\s*(AM|PM)$/i', $slot, $match)) {
                throw new InvalidArgumentException(sprintf('can\'t read shift "%s", use e.g. "2026-11-03 AM"', $slot));
            }

            $date = substr_count($match[1], '-') === 1 ? Carbon::now()->year . '-' . $match[1] : $match[1];
            [$year, $month, $day] = array_map('intval', explode('-', $date));
            if (!checkdate($month, $day, $year)) {
                throw new InvalidArgumentException(sprintf('"%s" is not a date', $match[1]));
            }
            $slots[] = ['day' => Carbon::create($year, $month, $day), 'half' => strtoupper($match[2])];
        }

        if ($slots && !$area) {
            throw new InvalidArgumentException('shifts need an area');
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
