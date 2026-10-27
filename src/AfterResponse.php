<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs work after the response was sent, as we can't host a queue worker
 *
 * The client (browser or Telegram) gets its answer right away; the PHP process keeps running.
 */
class AfterResponse
{
    public const TIME_LIMIT_SECONDS = 300;

    public static function run(LoggerInterface $log, string $what, callable $work): void
    {
        register_shutdown_function(function () use ($log, $what, $work): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            // With file sessions, the user's next request would wait for this work otherwise
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            ignore_user_abort(true);
            set_time_limit(self::TIME_LIMIT_SECONDS);

            try {
                $work();
            } catch (Throwable $e) {
                $log->error('{what} failed: {message} at {file}:{line}', [
                    'what'    => $what,
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ]);
            }
        });
    }
}
