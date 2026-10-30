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
        protected UrlGenerator $url
    ) {
    }

    public function index(): Response
    {
        $me = $this->bot->isConfigured() ? $this->bot->api()->call('getMe') : null;
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
     * Onboarding progress, today's shifts, open slots and the bot's activity
     */
    public function dashboard(): Response
    {
        return $this->response->withView('telegram/dashboard', Dashboard::data());
    }

    /**
     * The dashboard's auto refresh; a file of its own, as the site's CSP blocks inline scripts
     */
    public function dashboardScript(): Response
    {
        return $this->response
            ->withHeader('Content-Type', 'text/javascript')
            ->withContent((string) file_get_contents(__DIR__ . '/../resources/dashboard.js'));
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
        $this->bot->api()->call('setMyCommands', ['commands' => [
            ['command' => 'shifts', 'description' => 'Your upcoming shifts'],
            ['command' => 'team', 'description' => 'Leads: your team, onboarding and check-ins'],
            ['command' => 'gaps', 'description' => 'Leads: open slots today and tomorrow'],
        ]]);

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
        $me = $this->bot->api()->call('getMe');
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
