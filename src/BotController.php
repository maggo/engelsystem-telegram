<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Engelsystem\Http\Exceptions\HttpForbidden;
use Engelsystem\Http\Request;
use Engelsystem\Http\Response;
use Psr\Log\LoggerInterface;

/**
 * Public endpoints for Telegram and an optional external pinger, each with its own secret
 */
class BotController
{
    public const WEBHOOK_PATH = '/telegram/webhook';
    public const TICK_PATH = '/telegram/tick';

    public function __construct(
        protected Response $response,
        protected Bot $bot,
        protected LoggerInterface $log
    ) {
    }

    /**
     * Stores the update, answers Telegram right away and handles the update afterwards, so slow
     * sends never make Telegram time out and deliver the same update again. If the handling is
     * cut short, the scheduler handles the stored update again (Update::claimUnfinished()).
     */
    public function webhook(Request $request): Response
    {
        if (!Bot::verifyWebhookSecret($request->headers->get('X-Telegram-Bot-Api-Secret-Token', ''))) {
            throw new HttpForbidden();
        }

        $payload = $request->getContent();
        $update = json_decode($payload, true);
        if (!is_array($update) || !isset($update['update_id']) || !is_int($update['update_id'])) {
            return $this->response->withContent('ok');
        }

        if (Update::record($update['update_id'], $payload)) {
            AfterResponse::run($this->log, 'Telegram update', function () use ($update): void {
                $this->bot->api()->retryBriefly();
                $this->bot->handle($update);
                Update::processed($update['update_id']);
            });
        }

        return $this->response->withContent('ok');
    }

    /**
     * GET /telegram/tick?key=... for an external uptime pinger, in case the site is quiet; the
     * scheduler runs after the response like on any page view (TickMiddleware), so the pinger
     * never waits for it
     */
    public function tick(Request $request): Response
    {
        if (!Bot::verifyTickKey((string) $request->get('key'))) {
            throw new HttpForbidden();
        }

        return $this->response->withContent('ok');
    }
}
