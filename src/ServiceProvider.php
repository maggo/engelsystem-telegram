<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Engelsystem\Config\Config;
use Engelsystem\Container\ServiceProvider as BaseServiceProvider;
use Engelsystem\Middleware\VerifyCsrfToken as CoreVerifyCsrfToken;
use Psr\Log\LoggerInterface;

class ServiceProvider extends BaseServiceProvider
{
    public const PROVIDER = 'telegram';

    public function register(): void
    {
        $this->app->singleton(TelegramOidc::class, function (): TelegramOidc {
            return new TelegramOidc(
                (string) config('telegram_client_id', ''),
                (string) config('telegram_client_secret', ''),
                (float) config('guzzle_timeout', 2.0),
            );
        });

        $this->app->singleton(BotApi::class, function (): BotApi {
            return new BotApi((string) config('telegram_bot_token', ''), $this->app->get(LoggerInterface::class));
        });
        // One Format for both, it caches handles while an update is handled
        $this->app->singleton(Format::class);
        $this->app->singleton(Leads::class);
        $this->app->singleton(Bot::class);
        $this->app->singleton(Scheduler::class);

        // Lets the webhook through the CSRF check, see VerifyCsrfToken
        $this->app->bind(CoreVerifyCsrfToken::class, VerifyCsrfToken::class);

        // Picked up when the Twig service provider boots, after the core extensions
        $this->app->tag(MenuExtension::class, ['twig.extension']);
    }

    /**
     * Registers Telegram as an OAuth provider so it shows up on the login page and in
     * Settings > OAuth. The actual flow is handled by OAuthMiddleware, because Telegram has no
     * user info endpoint, which the stock OAuth controller needs.
     */
    public function boot(): void
    {
        /** @var Config $config */
        $config = $this->app->get('config');
        // Both, or the login button would lead to the stock OAuth controller, which can't do it
        if (!$config->get('telegram_client_id') || !$config->get('telegram_client_secret')) {
            return;
        }

        $config->set('oauth', [
            ...$config->get('oauth', []),
            self::PROVIDER => [
                'name'                  => 'Telegram',
                'client_id'             => $config->get('telegram_client_id'),
                'client_secret'         => '',
                'url_auth'              => TelegramOidc::URL_AUTH,
                'url_token'             => TelegramOidc::URL_TOKEN,
                'url_info'              => '',
                'id'                    => 'sub',
                // Disconnecting only removes the login, the bot chat would link it again on the
                // next login; resetting the link is up to the admins (Telegram & bot page)
                'allow_user_disconnect' => false,
            ],
        ]);
    }
}
