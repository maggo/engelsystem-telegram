<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\Carbon;
use Engelsystem\Controllers\BaseController;
use Engelsystem\Controllers\HasUserNotifications;
use Engelsystem\Controllers\NotificationType;
use Engelsystem\Helpers\Authenticator;
use Engelsystem\Http\Redirector;
use Engelsystem\Http\Request;
use Engelsystem\Http\Response;
use Engelsystem\Http\UrlGenerator;
use Engelsystem\Models\OAuth;
use Engelsystem\Models\User\User;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Bot setup, the volunteer import, the QR codes and the dashboard for coordinators
 */
class AdminController extends BaseController
{
    use HasUserNotifications;

    protected array $permissions = [
        'admin_user',
    ];

    public function __construct(
        protected Response $response,
        protected Redirector $redirect,
        protected Bot $bot,
        protected Authenticator $auth,
        protected UrlGenerator $url,
        protected Swap $swap,
        protected StandbyBoard $standby,
        protected LoggerInterface $log
    ) {
    }

    public function index(): Response
    {
        // A page view doesn't wait long for Telegram
        $this->bot->api()->retryBriefly();
        $me = $this->bot->me();
        $webhook = $me ? $this->bot->api()->call('getWebhookInfo') : null;

        return $this->response->withView('telegram/admin', [
            'bot'          => $me,
            'webhook'      => $webhook,
            'webhook_url'  => $this->url->to(BotController::WEBHOOK_PATH),
            'tick_url'     => $this->url->to(BotController::TICK_PATH, ['key' => Bot::tickKey()]),
            'last_tick'    => Scheduler::lastTick(),
            'now'          => Carbon::now(),
            'checkin_code' => Roster::checkinCode(),
            'done_code'    => Roster::onboardingCode(),
            'join_link'    => $me ? $this->startLink($me) : null,
            'chats'        => Chat::with('angelType')->get(),
        ]);
    }

    /**
     * One-time import of the final volunteer list, see Import
     */
    public function import(Request $request): Response
    {
        $result = Import::run((string) $request->postData('csv', ''), $this->auth->user());

        $this->addNotification(sprintf(
            '%d account(s) created, %d already existed and only got their areas and shifts.',
            $result['created'],
            count($result['existing'])
        ));
        foreach ($result['existing'] as $existing) {
            $this->addNotification($existing, NotificationType::WARNING);
        }
        foreach ($result['problems'] as $problem) {
            $this->addNotification($problem, NotificationType::ERROR);
        }

        return $this->redirect->to('/admin/telegram');
    }

    /**
     * Creates the shifts for the volunteer import, see Import::shifts()
     */
    public function importShifts(Request $request): Response
    {
        $result = Import::shifts((string) $request->postData('csv', ''), $this->auth->user());

        $this->addNotification(sprintf(
            '%d shift(s) created, %d already existed.',
            $result['created'],
            count($result['merged'])
        ));
        foreach ($result['merged'] as $merged) {
            $this->addNotification($merged, NotificationType::WARNING);
        }
        foreach ($result['problems'] as $problem) {
            $this->addNotification($problem, NotificationType::ERROR);
        }

        return $this->redirect->to('/admin/telegram');
    }

    /**
     * Sets the Telegram username an account waits for, e.g. for someone created in Engelsystem,
     * or when the wrong person connected: clears the bot chat and the website login and logs them out
     *
     * Accounts with more rights than a volunteer never wait for a handle, as whoever holds it
     * would get them: they log in with their password and connect Telegram in their settings.
     */
    public function unlink(Request $request): Response
    {
        $name = TelegramAccount::normalize((string) $request->postData('account', ''));
        $user = $name === '' ? null : (TelegramAccount::findByHandle($name)?->user
            ?? User::query()->whereRaw('LOWER(name) = ?', [$name])->first());
        if (!$user) {
            $this->addNotification(sprintf('No account found for "%s".', $name), NotificationType::ERROR);
            return $this->redirect->to('/admin/telegram');
        }

        $privileged = $user->groups()->where('groups.id', '!=', $this->auth->getDefaultRole())->exists();
        $handle = $privileged
            ? null
            : (TelegramAccount::normalize((string) $request->postData('handle', '')) ?: mb_strtolower($user->name));
        $taken = $handle ? TelegramAccount::findByHandle($handle) : null;
        if ($taken && $taken->user_id !== $user->id) {
            $this->addNotification(
                sprintf('@%s already belongs to the account "%s".', $handle, $taken->user->name),
                NotificationType::ERROR
            );
            return $this->redirect->to('/admin/telegram');
        }

        $wasLinked = TelegramAccount::query()->whereKey($user->id)->whereNotNull('chat_id')->exists()
            || OAuth::query()->where('user_id', $user->id)->where('provider', ServiceProvider::PROVIDER)->exists();
        OAuth::query()->where('user_id', $user->id)->where('provider', ServiceProvider::PROVIDER)->delete();
        TelegramAccount::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['telegram_id' => null, 'chat_id' => null, 'username' => $handle]
        );
        $user->sessions()->delete();

        if ($privileged) {
            Roster::log(sprintf('%s reset the Telegram link of %s', $this->auth->user()->name, $user->name));
            $this->addNotification(sprintf(
                'Telegram link of "%s" removed. This account has more rights than a volunteer, so it '
                . 'connects again in its settings (Settings > OAuth) after logging in with its password.',
                $user->name
            ));
            return $this->redirect->to('/admin/telegram');
        }

        Roster::log(sprintf(
            '%s %s the Telegram link of %s, waiting for @%s',
            $this->auth->user()->name,
            $wasLinked ? 'reset' : 'set',
            $user->name,
            $handle
        ));
        $this->addNotification(sprintf(
            '%s@%s can connect now by messaging the bot.',
            $wasLinked ? sprintf('Telegram link of "%s" removed. ', $user->name) : sprintf('"%s" waits for Telegram: ', $user->name),
            $handle
        ));

        return $this->redirect->to('/admin/telegram');
    }

    /**
     * Open slots today and tomorrow, a day's check-ins and onboarding progress
     */
    public function dashboard(Request $request): Response
    {
        return $this->response->withView('telegram/dashboard', Dashboard::data($this->query($request, 'day')));
    }

    /**
     * The dashboard's auto refresh; a file of its own, as the site's CSP blocks inline scripts
     */
    public function dashboardScript(): Response
    {
        return $this->script('dashboard.js');
    }

    /**
     * A day's shifts at a glance, a page of its own for a screen, see Overview
     */
    public function overview(Request $request): Response
    {
        return $this->response->withView('telegram/overview', Overview::data($this->query($request, 'day')));
    }

    /**
     * The overview's updates; a file of its own, as the site's CSP blocks inline scripts
     */
    public function overviewScript(): Response
    {
        return $this->script('overview.js');
    }

    /**
     * The live dashboard for the desk: a day's numbers and shifts, updated in place, see LiveDashboard
     */
    public function live(Request $request): Response
    {
        return $this->response->withView('telegram/live', LiveDashboard::data($this->query($request, 'day')));
    }

    /**
     * The live dashboard's updates, pop-ups and confetti; a file of its own, as the site's CSP blocks inline scripts
     */
    public function liveScript(): Response
    {
        return $this->script('live.js');
    }

    /**
     * The day's check-ins per volunteer, and check-ins by hand, see CheckinList
     */
    public function checkins(Request $request): Response
    {
        $user = $this->query($request, 'user');

        return $this->response->withView('telegram/checkins', [
            ...CheckinList::data(
                $this->query($request, 'day'),
                $this->query($request, 'search'),
                is_numeric($user) ? (int) $user : null
            ),
            'open' => $this->fragment($this->query($request, 'open')),
        ]);
    }

    /**
     * Needed, assigned and no-shows per shift of a day, see Attendance
     */
    public function attendance(Request $request): Response
    {
        return $this->response->withView('telegram/attendance', Attendance::data($this->query($request, 'day')));
    }

    /**
     * Picking another day reloads the page (check-in and attendance); a file of its own, as the site's CSP blocks inline scripts
     */
    public function checkinsScript(): Response
    {
        return $this->script('checkins.js');
    }

    /**
     * Checks someone in for a day with shifts, or takes their check-in back
     */
    public function checkinsSet(Request $request): Response
    {
        $user = User::query()->find((int) $this->posted($request, 'user'));
        $day = $this->posted($request, 'day');
        // Back to where it was clicked: the shift or list (kept open), or the search
        $open = $this->fragment($this->posted($request, 'open'));
        $back = $this->url->to('/admin/telegram/checkins', array_filter([
            'day'    => $open !== '' ? $day : $request->postData('view_day'),
            'open'   => $open,
            'search' => $open !== '' ? null : $request->postData('search'),
            'user'   => $open !== '' || $request->postData('search') ? null : $request->postData('user'),
        ])) . ($open !== '' ? '#open-' . $open : '#find');
        if (!$user || !Checkin::days()->has($day)) {
            $this->addNotification('Unknown person or a day without shifts.', NotificationType::ERROR);
            return $this->redirect->to($back);
        }

        $date = Carbon::parse($day);
        $label = $date->format('D M j');
        $by = $this->auth->user()->name;
        if ($request->postData('checked_in')) {
            if (Checkin::record($user->id, $date, byHand: true)) {
                Roster::log(sprintf('%s checked in %s for %s', $by, $user->name, $label));
            }
            $this->addNotification(sprintf('%s is checked in for %s.', $user->displayName, $label));
        } else {
            if (Checkin::remove($user->id, $date)) {
                Roster::log(sprintf('%s took back the check-in of %s for %s', $by, $user->name, $label));
            }
            $this->addNotification(sprintf('%s is no longer checked in for %s.', $user->displayName, $label));
        }

        return $this->redirect->to($back);
    }

    /**
     * Checks in every lead (supporter of any area) for every day with shifts
     */
    public function checkinsLeads(): Response
    {
        $leads = Roster::leadIds();
        $days = Checkin::days();
        $new = Checkin::recordAll($leads, $days->keys());
        if ($new) {
            Roster::log(sprintf(
                '%s checked in all %d leads for every day with shifts (%d new check-ins)',
                $this->auth->user()->name,
                $leads->count(),
                $new
            ));
        }

        $this->addNotification(sprintf(
            'All %d leads are checked in for %d days, %d check-ins were new.',
            $leads->count(),
            $days->count(),
            $new
        ));

        return $this->redirect->to('/admin/telegram/checkins');
    }

    /**
     * Swapping people between two shifts of the same half day, see Swap
     */
    public function swap(Request $request): Response
    {
        return $this->response->withView('telegram/swap', Swap::data(
            $this->query($request, 'day'),
            $this->query($request, 'half'),
            $this->query($request, 'left'),
            $this->query($request, 'right')
        ));
    }

    public function swapScript(): Response
    {
        return $this->script('swap.js');
    }

    public function swapMove(Request $request): Response
    {
        $left = (string) $request->postData('left');
        $right = (string) $request->postData('right');
        try {
            $by = $this->auth->user();
            $result = $this->swap->run(
                $left,
                $right,
                $this->postedArray($request, 'from_left'),
                $this->postedArray($request, 'from_right'),
                $by
            );
            AfterResponse::run($this->log, 'Swap messages', fn() => $this->swap->tell($result, $by));

            $this->addNotification(sprintf(
                'Moved %s. The bot tells them, the leads of both shifts and coordination.',
                Swap::summary($result)
            ));
            if ($unreachable = Swap::unreachable($result)) {
                $this->addNotification(
                    'Not on Telegram, please tell them directly: '
                    . implode(', ', array_map(fn(User $user) => $user->displayName, $unreachable)),
                    NotificationType::WARNING
                );
            }
        } catch (InvalidArgumentException $e) {
            $this->addNotification($e->getMessage(), NotificationType::ERROR);
        }

        return $this->redirect->to($this->url->to('/admin/telegram/swap', [
            'day'   => $request->postData('day'),
            'half'  => $request->postData('half'),
            'left'  => $left,
            'right' => $right,
        ]));
    }

    /**
     * Moving anyone between an area's shift and the standby of the same half day, see StandbyBoard
     */
    public function standby(Request $request): Response
    {
        return $this->response->withView('telegram/standby', StandbyBoard::data(
            $this->query($request, 'day'),
            $this->query($request, 'half')
        ));
    }

    public function standbyScript(): Response
    {
        return $this->script('standby.js');
    }

    public function standbyMove(Request $request): Response
    {
        $entry = $this->posted($request, 'entry');
        $to = $this->postedArray($request, 'to')[$entry] ?? null;
        try {
            $by = $this->auth->user();
            $result = $this->standby->move($entry, $to, $by);
            AfterResponse::run($this->log, 'Standby move messages', fn() => $this->swap->tell($result, $by));

            $this->addNotification(sprintf(
                'Moved %s. The bot tells them, the leads of both shifts and coordination.',
                Swap::summary($result)
            ));
            if ($unreachable = Swap::unreachable($result)) {
                $this->addNotification(
                    'Not on Telegram, please tell them directly: '
                    . implode(', ', array_map(fn(User $user) => $user->displayName, $unreachable)),
                    NotificationType::WARNING
                );
            }
        } catch (InvalidArgumentException $e) {
            $this->addNotification($e->getMessage(), NotificationType::ERROR);
        }

        return $this->redirect->to($this->url->to('/admin/telegram/standby', [
            'day'  => $request->postData('day'),
            'half' => $request->postData('half'),
        ]));
    }

    /**
     * Every shift's need in one table, see Needs
     */
    public function needs(): Response
    {
        return $this->response->withView('telegram/needs', Needs::data());
    }

    public function needsScript(): Response
    {
        return $this->script('needs.js');
    }

    public function needsSave(Request $request): Response
    {
        try {
            $changed = Needs::save(
                $this->postedArray($request, 'need'),
                $this->postedArray($request, 'was'),
                $this->auth->user()
            );
            $this->addNotification($changed
                ? sprintf('%d need(s) changed: %s.', count($changed), implode('; ', $changed))
                : 'Nothing changed.');
        } catch (InvalidArgumentException $e) {
            $this->addNotification($e->getMessage(), NotificationType::ERROR);
        }

        return $this->redirect->to('/admin/telegram/needs');
    }

    /**
     * A query parameter as text, null when missing or not text (?day[]=x)
     */
    protected function query(Request $request, string $key): ?string
    {
        $value = $request->get($key);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * A form field as text, empty when missing or not text
     */
    protected function posted(Request $request, string $key): string
    {
        $value = $request->postData($key);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A form field holding a list (name[...]), empty when missing or not a list
     */
    protected function postedArray(Request $request, string $key): array
    {
        $value = $request->request->all()[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * Which list or shift to open again: an anchor name, nothing else
     */
    protected function fragment(?string $open): string
    {
        return preg_match('/^[a-z0-9-]{1,40}$/', (string) $open) ? (string) $open : '';
    }

    protected function script(string $file): Response
    {
        return $this->response
            ->withHeader('Content-Type', 'text/javascript')
            ->withContent((string) file_get_contents(__DIR__ . '/../resources/' . $file));
    }

    /**
     * Points the bot's webhook at this site, or removes it
     */
    public function bot(Request $request): Response
    {
        if ($request->postData('action') === 'disconnect') {
            $this->bot->api()->call('deleteWebhook');
            $this->addNotification('Bot webhook removed.');
            return $this->redirect->to('/admin/telegram');
        }

        $ok = $this->bot->api()->call('setWebhook', [
            'url'             => $this->url->to(BotController::WEBHOOK_PATH),
            'secret_token'    => Bot::webhookSecret(),
            'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
            // Each update keeps a PHP worker busy for a moment; leave enough for the website
            'max_connections' => 8,
        ]);
        $this->bot->setCommands();

        $this->addNotification(
            $ok ? 'Bot connected to ' . $this->url->to(BotController::WEBHOOK_PATH) : 'Connecting the bot failed, see log.',
            $ok ? NotificationType::MESSAGE : NotificationType::ERROR
        );

        return $this->redirect->to('/admin/telegram');
    }

    /**
     * Today's check-in or onboarding completion QR code as SVG, to show at the venue
     */
    public function qr(Request $request): Response
    {
        $this->bot->api()->retryBriefly();
        $me = $this->bot->me();
        if (!$me) {
            // Never a guessed link: it would send people to the wrong bot
            return $this->response->withStatus(503)->withContent('Telegram is not reachable, try again.');
        }
        $link = match ($request->get('for')) {
            'done'  => $this->startLink($me, 'done_' . Roster::onboardingCode()),
            'join'  => $this->startLink($me),
            default => $this->startLink($me, 'checkin_' . Roster::checkinCode()),
        };
        $svg = (new Writer(new ImageRenderer(new RendererStyle(400, 2), new SvgImageBackEnd())))->writeString($link);

        return $this->response
            ->withHeader('Content-Type', 'image/svg+xml')
            ->withContent($svg);
    }

    protected function startLink(array $me, string $start = ''): string
    {
        return sprintf('https://t.me/%s', $me['username']) . ($start !== '' ? '?start=' . $start : '');
    }
}
