<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the bot's scheduled checks on regular web traffic, as we can't host a cron job
 *
 * The work happens after the response was sent to the browser, at most every 30 seconds
 * across all web pods (see Scheduler::claim()).
 */
class TickMiddleware implements MiddlewareInterface
{
    public function __construct(protected Scheduler $scheduler, protected LoggerInterface $log)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($this->scheduler->isEnabled()) {
            AfterResponse::run($this->log, 'Telegram scheduler', fn() => $this->scheduler->tick());
        }

        return $response;
    }
}
