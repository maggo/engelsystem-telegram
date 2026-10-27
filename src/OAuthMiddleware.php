<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Engelsystem\Controllers\AuthController;
use Engelsystem\Controllers\NotificationType;
use Engelsystem\Helpers\Authenticator;
use Engelsystem\Http\Redirector;
use Engelsystem\Http\UrlGenerator;
use Engelsystem\Models\OAuth;
use Engelsystem\Models\User\User;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Throwable;

/**
 * Handles GET /oauth/telegram (start + callback) and POST /oauth/telegram/connect
 *
 * Volunteers log in to the account the import created for their Telegram handle; nobody can sign up.
 *
 * Plugin routes are registered after the core /oauth/{provider} routes, so they can't take over
 * these paths; this middleware answers them before the stock OAuth controller is reached.
 * Disconnecting is left to the stock controller.
 */
class OAuthMiddleware implements MiddlewareInterface
{
    protected const PATH = '/oauth/' . ServiceProvider::PROVIDER;

    public function __construct(protected ContainerInterface $app)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Engelsystem's request returns the URI as a string instead of a PSR-7 UriInterface
        $path = rtrim((string) parse_url((string) $request->getUri(), PHP_URL_PATH), '/');
        $isStart = str_ends_with($path, self::PATH) && $request->getMethod() === 'GET';
        $isConnect = str_ends_with($path, self::PATH . '/connect') && $request->getMethod() === 'POST';

        /** @var TelegramOidc $oidc */
        $oidc = $this->app->get(TelegramOidc::class);
        if ((!$isStart && !$isConnect) || !$oidc->isConfigured()) {
            return $handler->handle($request);
        }

        if ($isConnect) {
            $this->session()->set('telegram_connect', true);
            return $this->start($oidc);
        }

        $query = $request->getQueryParams();
        if (empty($query['code']) && empty($query['error'])) {
            return $this->start($oidc);
        }

        try {
            return $this->callback($oidc, $query);
        } catch (Throwable $e) {
            $this->app->get(LoggerInterface::class)->error(
                'Telegram login failed: {message}',
                ['message' => $e->getMessage()]
            );
            return $this->fail('telegram.error');
        }
    }

    protected function start(TelegramOidc $oidc): ResponseInterface
    {
        $provider = $oidc->provider($this->redirectUri());
        $authUrl = $provider->getAuthorizationUrl();

        $this->session()->set('telegram_state', $provider->getState());
        $this->session()->set('telegram_pkce', $provider->getPkceCode());

        return $this->redirect()->to($authUrl);
    }

    protected function callback(TelegramOidc $oidc, array $query): ResponseInterface
    {
        $session = $this->session();
        $expectedState = $session->remove('telegram_state');
        $pkceCode = $session->remove('telegram_pkce');
        $isConnect = (bool) $session->remove('telegram_connect');

        if (!empty($query['error'])) {
            return $this->fail('telegram.cancelled');
        }

        if (!$expectedState || !hash_equals((string) $expectedState, (string) ($query['state'] ?? ''))) {
            return $this->fail('telegram.error');
        }

        $provider = $oidc->provider($this->redirectUri());
        $provider->setPkceCode((string) $pkceCode);
        $token = $provider->getAccessToken('authorization_code', ['code' => $query['code']]);

        $idToken = $token->getValues()['id_token'] ?? null;
        if (!$idToken) {
            return $this->fail('telegram.error');
        }

        $claims = $oidc->verifyIdToken($idToken);
        $telegramId = (string) $claims['sub'];
        // The numeric Telegram user id, the same the bot sees. Unlike the handle it never changes
        // and can't be passed on, so it decides who an account belongs to once it is linked.
        $userId = (string) ($claims['id'] ?? '');
        if (!ctype_digit($userId)) {
            return $this->fail('telegram.error');
        }

        /** @var OAuth|null $oauth */
        $oauth = OAuth::query()
            ->where('provider', ServiceProvider::PROVIDER)
            ->where('identifier', $telegramId)
            ->first();

        /** @var Authenticator $auth */
        $auth = $this->app->get(Authenticator::class);
        $user = $auth->user();

        if ($user) {
            if (($oauth && $oauth->user_id !== $user->id) || $this->linkedElsewhere($user, $userId)) {
                return $this->fail('telegram.already-linked');
            }

            if (!$oauth && $isConnect) {
                $oauth = new OAuth(['provider' => ServiceProvider::PROVIDER, 'identifier' => $telegramId]);
                $oauth->user()->associate($user)->save();
            }

            if ($oauth) {
                $this->saveAccount($user, $telegramId, $userId, $claims);
                $this->notify('telegram.connected');
            }

            return $this->redirect()->to('/settings/oauth');
        }

        $user = $oauth?->user ?? $this->findAccount($userId, $claims);
        if (!$user) {
            return $this->fail('telegram.not-on-list');
        }
        if ($this->linkedElsewhere($user, $userId)) {
            return $this->fail('telegram.already-linked');
        }

        // Only now that it's clear the account is theirs
        $this->app->get('db.connection')->transaction(function () use ($oauth, $user, $telegramId, $userId, $claims): void {
            if (!$oauth) {
                (new OAuth(['provider' => ServiceProvider::PROVIDER, 'identifier' => $telegramId]))->user()->associate($user)->save();
                Roster::log(sprintf('%s logged in to the website with Telegram for the first time', $user->name));
            }
            $this->saveAccount($user, $telegramId, $userId, $claims);
        });

        return $this->app->get(AuthController::class)->loginUser($user);
    }

    /**
     * First website login: the account the bot already linked to this Telegram user, or else an
     * account that waits for their handle (import, reset link) and nobody claimed yet. Nobody can
     * create an account by logging in.
     */
    protected function findAccount(string $userId, array $claims): ?User
    {
        /** @var Authenticator $auth */
        $auth = $this->app->get(Authenticator::class);
        $account = TelegramAccount::query()->where('chat_id', $userId)->first()
            ?? TelegramAccount::waitingFor((string) ($claims['preferred_username'] ?? ''), $auth->getDefaultRole());
        return $account?->user;
    }

    /**
     * The Telegram user belongs to another account, or the account to another Telegram user
     */
    protected function linkedElsewhere(User $user, string $userId): bool
    {
        $owner = TelegramAccount::query()->where('chat_id', $userId)->value('user_id');
        $linked = TelegramAccount::query()->whereKey($user->id)->value('chat_id');

        return ($owner !== null && $owner !== $user->id) || ($linked !== null && $linked !== $userId);
    }

    protected function saveAccount(User $user, string $telegramId, string $userId, array $claims): void
    {
        $handle = $claims['preferred_username'] ?? null;
        TelegramAccount::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'telegram_id' => $telegramId,
                // The bot's private chat with someone has their user id
                'chat_id'     => $userId,
                ...($handle ? ['username' => $handle] : []),
            ]
        );
    }

    protected function fail(string $message): ResponseInterface
    {
        $this->notify($message, NotificationType::ERROR);

        $user = $this->app->get(Authenticator::class)->user();
        return $this->redirect()->to($user ? '/settings/oauth' : '/login');
    }

    protected function notify(string $message, NotificationType $type = NotificationType::MESSAGE): void
    {
        $key = 'messages.' . $type->value;
        $this->session()->set($key, array_merge($this->session()->get($key, []), [$message]));
    }

    protected function redirectUri(): string
    {
        return $this->app->get(UrlGenerator::class)->to(self::PATH);
    }

    protected function session(): SessionInterface
    {
        return $this->app->get('session');
    }

    protected function redirect(): Redirector
    {
        return $this->app->get(Redirector::class);
    }
}
