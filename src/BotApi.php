<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Minimal Telegram Bot API client
 *
 * Never throws: failures are logged and return null, so a handler always runs to the end.
 * Calls are spaced out to stay below Telegram's ~30 messages per second, and rate limits (429),
 * server errors and timeouts are retried a few times.
 *
 * @see https://core.telegram.org/bots/api
 * @see https://core.telegram.org/bots/faq#my-bot-is-hitting-limits-how-do-i-avoid-this
 */
class BotApi
{
    protected const MIN_INTERVAL_MICROSECONDS = 40_000;
    protected int $maxAttempts = 3;

    protected int $maxRetryWaitSeconds = 10;

    protected GuzzleClient $client;

    protected float $lastCallAt = 0;

    protected bool $failedTemporarily = false;

    public function __construct(protected string $token, protected LoggerInterface $log)
    {
        $this->client = new GuzzleClient([
            'base_uri'        => 'https://api.telegram.org/bot' . $token . '/',
            'connect_timeout' => 5,
            'timeout'         => 10,
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    /**
     * @return array<string, mixed>|null The result, or null if the call failed (see failedTemporarily())
     */
    public function call(string $method, array $params = []): mixed
    {
        $this->failedTemporarily = false;

        for ($attempt = 1;; $attempt++) {
            $this->throttle();

            try {
                $response = $this->client->post($method, ['json' => $params]);
                return json_decode((string) $response->getBody(), true)['result'] ?? null;
            } catch (GuzzleException $e) {
                $errorResponse = $e instanceof RequestException ? $e->getResponse() : null;
                $status = $errorResponse?->getStatusCode();
                $error = $errorResponse ? json_decode((string) $errorResponse->getBody(), true) : null;

                // No answer at all (timeout, network), rate limited or Telegram having trouble
                $temporary = $status === null || $status === 429 || $status >= 500;
                $wait = max(1, (int) ($error['parameters']['retry_after'] ?? 1));
                if ($temporary && $attempt < $this->maxAttempts && $wait <= $this->maxRetryWaitSeconds) {
                    sleep($wait);
                    continue;
                }

                // The exception message would contain the bot token from the URL, so it isn't logged
                $this->log->warning('Telegram {method} failed: {status} {error}', [
                    'method' => $method,
                    'status' => $status ?? 'no response',
                    'error'  => $error['description'] ?? get_class($e),
                ]);
                $this->failedTemporarily = $temporary;

                return null;
            }
        }
    }

    /**
     * Retry briefly only: for answering updates, so a flood of messages can't keep PHP workers
     * waiting on Telegram; scheduled messages that fail are sent again on a later tick anyway
     */
    public function retryBriefly(): void
    {
        $this->maxAttempts = 2;
        $this->maxRetryWaitSeconds = 2;
    }

    /**
     * Whether the last call failed for a reason that may go away (Telegram unreachable or rate
     * limiting), as opposed to Telegram refusing it (e.g. the user never started the bot)
     */
    public function failedTemporarily(): bool
    {
        return $this->failedTemporarily;
    }

    /**
     * @param array<array<array{text: string, callback_data?: string, url?: string}>> $buttons rows of buttons
     */
    public function send(string|int $chatId, string $text, array $buttons = []): mixed
    {
        $params = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'link_preview_options'     => ['is_disabled' => true],
        ];
        if ($buttons) {
            $params['reply_markup'] = ['inline_keyboard' => $buttons];
        }

        return $this->call('sendMessage', $params);
    }

    public function edit(string|int $chatId, int $messageId, string $text, array $buttons = []): mixed
    {
        return $this->call('editMessageText', [
            'chat_id'              => $chatId,
            'message_id'           => $messageId,
            'text'                 => $text,
            'parse_mode'           => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup'         => ['inline_keyboard' => $buttons],
        ]);
    }

    protected function throttle(): void
    {
        $wait = (int) (self::MIN_INTERVAL_MICROSECONDS - (microtime(true) - $this->lastCallAt) * 1_000_000);
        if ($wait > 0) {
            usleep($wait);
        }

        $this->lastCallAt = microtime(true);
    }
}
