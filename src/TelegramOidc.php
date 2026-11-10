<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Engelsystem\Models\EventConfig;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Carbon;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GenericProvider;
use RuntimeException;
use Throwable;

/**
 * Telegram login via OpenID Connect (authorization code flow with PKCE)
 *
 * @see https://core.telegram.org/bots/telegram-login
 */
class TelegramOidc
{
    public const URL_AUTH = 'https://oauth.telegram.org/auth';
    public const URL_TOKEN = 'https://oauth.telegram.org/token';
    public const URL_JWKS = 'https://oauth.telegram.org/.well-known/jwks.json';
    public const ISSUER = 'https://oauth.telegram.org';
    public const SCOPES = ['openid', 'profile', 'telegram:bot_access'];

    public function __construct(
        protected string $clientId,
        protected string $clientSecret,
        protected float $timeout
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function provider(string $redirectUri): GenericProvider
    {
        return new GenericProvider(
            [
                'clientId'                => $this->clientId,
                'clientSecret'            => $this->clientSecret,
                'redirectUri'             => $redirectUri,
                'urlAuthorize'            => self::URL_AUTH,
                'urlAccessToken'          => self::URL_TOKEN,
                // Telegram has no user info endpoint, all claims are in the ID token
                'urlResourceOwnerDetails' => self::URL_TOKEN,
                'scopes'                  => self::SCOPES,
                'scopeSeparator'          => ' ',
                'pkceMethod'              => AbstractProvider::PKCE_METHOD_S256,
            ],
            ['httpClient' => new GuzzleClient(['timeout' => $this->timeout])]
        );
    }

    public const JWKS_KEY = 'telegram_oidc_jwks';
    protected const JWKS_TTL = 86400; // 24 hours

    /**
     * Retrieves the JWKS key set, caching it in event_config for up to 24 hours.
     *
     * @return array<string, mixed>
     */
    public function fetchJwks(bool $fresh = false): array
    {
        if (!$fresh) {
            try {
                $stored = EventConfig::query()->find(self::JWKS_KEY);
                if ($stored && $stored->updated_at && $stored->updated_at->gt(Carbon::now()->subSeconds(self::JWKS_TTL))) {
                    $val = $stored->value;
                    if (is_array($val) && !empty($val['keys'])) {
                        return $val;
                    }
                }
            } catch (Throwable) {
                // If database is unavailable, fall through to live fetch
            }
        }

        $body = (new GuzzleClient(['timeout' => $this->timeout]))
            ->get(self::URL_JWKS)
            ->getBody()
            ->getContents();

        $jwks = json_decode($body, true);
        if (is_array($jwks) && !empty($jwks['keys'])) {
            try {
                EventConfig::query()->updateOrCreate(
                    ['name' => self::JWKS_KEY],
                    ['value' => $jwks, 'updated_at' => Carbon::now()]
                );
            } catch (Throwable) {
            }

            return $jwks;
        }

        return is_array($jwks) ? $jwks : [];
    }

    /**
     * Verifies the ID token signature against Telegram's keys and returns its claims
     *
     * @return array<string, mixed>
     */
    public function verifyIdToken(string $idToken): array
    {
        JWT::$leeway = 60;

        $jwks = $this->fetchJwks();
        try {
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($jwks, 'RS256'));
        } catch (Throwable) {
            // Key might have rotated; try once with fresh JWKS
            $jwks = $this->fetchJwks(fresh: true);
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($jwks, 'RS256'));
        }

        if (($claims['iss'] ?? null) !== self::ISSUER) {
            throw new RuntimeException('Invalid issuer');
        }

        if (!in_array($this->clientId, array_map('strval', (array) ($claims['aud'] ?? [])), true)) {
            throw new RuntimeException('Invalid audience');
        }

        if (empty($claims['sub'])) {
            throw new RuntimeException('Missing subject');
        }

        return $claims;
    }
}
