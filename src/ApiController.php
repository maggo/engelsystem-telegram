<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Carbon\Carbon;
use Engelsystem\Http\Request;
use Engelsystem\Http\Response;
use Engelsystem\Models\User\User;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

/**
 * Read-only JSON for dashboards, see Export. Engelsystem only takes API keys under /api (a
 * plugin can't add routes there, its catch-all comes first), so these check the key themselves,
 * the same way: Authorization: Bearer <key> or x-api-key, never in the URL. The key's account
 * needs the api privilege and admin_user, like the plugin dashboard; the log also admin_log.
 */
class ApiController
{
    public function __construct(
        protected Response $response,
        protected SessionStorageInterface $session,
        protected LoggerInterface $log
    ) {
    }

    public function shifts(Request $request): Response
    {
        return $this->respond($request, ['admin_user'], fn(?string $day) => ['data' => Export::shifts($day)]);
    }

    public function entries(Request $request): Response
    {
        return $this->respond($request, ['admin_user'], fn(?string $day) => ['data' => Export::entries($day)]);
    }

    public function checkins(Request $request): Response
    {
        return $this->respond($request, ['admin_user'], fn(?string $day) => ['data' => Export::checkins($day)]);
    }

    public function people(Request $request): Response
    {
        return $this->respond($request, ['admin_user'], fn() => ['data' => Export::people()]);
    }

    /**
     * ?after=<id> continues where the previous page ended (its next), ?since=<time> starts there
     */
    public function log(Request $request): Response
    {
        return $this->respond($request, ['admin_user', 'admin_log'], function () use ($request): array|string {
            $since = $request->get('since', '');
            $since = is_scalar($since) ? (string) $since : 'invalid';
            try {
                // The database holds local times, a time with another offset has to be converted
                $since = $since === '' ? null : Carbon::parse($since)->setTimezone(date_default_timezone_get());
            } catch (\Throwable) {
                return 'since must be a date and time, e.g. 2026-11-03T08:00:00+05:30';
            }

            $after = $request->get('after', 0);

            return Export::log(is_scalar($after) ? max(0, (int) $after) : 0, $since);
        });
    }

    /**
     * Browser preflight, as for Engelsystem's API
     */
    public function options(Request $request): Response
    {
        $this->forgetSession($request);

        return $this->headers($this->response)
            ->setStatusCode(200)
            ->withHeader('allow', 'OPTIONS, HEAD, GET')
            ->withHeader('access-control-allow-headers', 'Authorization, x-api-key');
    }

    /**
     * @param string[] $privileges needed besides api
     * @param callable(?string): (array|string) $data a string is an error in the request
     */
    protected function respond(Request $request, array $privileges, callable $data): Response
    {
        $this->forgetSession($request);
        $user = $this->user($request);
        if (!$user) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }
        if (array_diff(['api', ...$privileges], $user->privileges->pluck('name')->all())) {
            return $this->json(['message' => 'Forbidden'], 403);
        }

        $day = $request->get('day', '');
        $day = is_scalar($day) ? (string) $day : 'invalid';
        if (
            $day !== ''
            && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $date) || !checkdate((int) $date[2], (int) $date[3], (int) $date[1]))
        ) {
            return $this->json(['message' => 'day must be a date like 2026-11-03'], 400);
        }

        try {
            $result = $data($day === '' ? null : $day);
        } catch (\Throwable $e) {
            // JSON for a dashboard, as Engelsystem's own API answers errors
            $this->log->error('Telegram API: ' . $e->getMessage(), ['exception' => $e]);
            return $this->json(['message' => 'Internal error'], 500);
        }

        return is_string($result) ? $this->json(['message' => $result], 400) : $this->json($result);
    }

    protected function user(Request $request): ?User
    {
        $key = $request->headers->get('authorization', '');
        $key = Str::startsWith(Str::lower($key), 'bearer ')
            ? trim(Str::substr($key, 7))
            : (string) $request->headers->get('x-api-key', '');

        return $key === '' ? null : User::query()->where('api_key', $key)->first();
    }

    /**
     * Like Engelsystem does for its API: a request without a session cookie leaves no session
     * behind, so a polling dashboard doesn't fill the sessions table
     */
    protected function forgetSession(Request $request): void
    {
        if ($this->session instanceof NativeSessionStorage && !$request->cookies->has($this->session->getName())) {
            session_destroy();
        }
    }

    protected function json(array $data, int $status = 200): Response
    {
        return $this->headers($this->response)
            ->setStatusCode($status)
            ->withContent(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function headers(Response $response): Response
    {
        return $response
            ->withHeader('content-type', 'application/json')
            ->withHeader('access-control-allow-origin', '*');
    }
}
