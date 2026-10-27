<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Engelsystem\Middleware\VerifyCsrfToken as BaseVerifyCsrfToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets Telegram's webhook calls through the core CSRF check
 *
 * Telegram can't send a CSRF token, but it sends the secret we registered the webhook with.
 * Plugin middleware runs after the CSRF check, so the plugin replaces the core middleware in
 * the container instead. Every other request is checked as before.
 */
class VerifyCsrfToken extends BaseVerifyCsrfToken
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = rtrim((string) parse_url((string) $request->getUri(), PHP_URL_PATH), '/');
        if (
            $request->getMethod() === 'POST'
            && str_ends_with($path, BotController::WEBHOOK_PATH)
            && Bot::verifyWebhookSecret($request->getHeaderLine('X-Telegram-Bot-Api-Secret-Token'))
        ) {
            return $handler->handle($request);
        }

        return parent::process($request, $handler);
    }
}
