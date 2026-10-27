<?php

declare(strict_types=1);

namespace Telegram\Plugin;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client as GuzzleClient;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GenericProvider;
use RuntimeException;

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

    /**
     * Verifies the ID token signature against Telegram's keys and returns its claims
     *
     * @return array<string, mixed>
     */
    public function verifyIdToken(string $idToken): array
    {
        $jwks = (new GuzzleClient(['timeout' => $this->timeout]))
            ->get(self::URL_JWKS)
            ->getBody()
            ->getContents();

        JWT::$leeway = 60;
        $claims = (array) JWT::decode($idToken, JWK::parseKeySet(json_decode($jwks, true), 'RS256'));

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
