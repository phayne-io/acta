<?php

declare(strict_types=1);

namespace Acta\Oidc\Testing;

use Acta\Oidc\Client\Pkce;
use Acta\Oidc\Jose\KeyFactory;
use Acta\Oidc\Jose\WebTokenJose;
use Acta\Oidc\SystemClock;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * An identity provider living in memory, to test an application without network.
 *
 * It is a PSR-18 client: give it wherever an HTTP client to the provider is expected.
 * It serves a discovery document, a JWKS, a token endpoint (client credentials,
 * authorization code with PKCE, refresh token, device code), a device authorization
 * endpoint and a UserInfo endpoint, and signs real tokens with its own keys.
 *
 *     $idp = new FakeIdentityProvider($responseFactory, $streamFactory);
 *     $token = $idp->issueAccessToken(['sub' => 'ada', 'scope' => 'accounts:write']);
 *
 * It checks what a real provider checks, client credentials, PKCE and redirect URIs
 * included, so that a test passing against it means something. It is not a provider
 * to deploy.
 */
final class FakeIdentityProvider implements ClientInterface
{
    /** @var list<RequestInterface> every request received, in order */
    public array $requests = [];

    /** Whether the provider answers; turn it off to simulate an outage. */
    public bool $available = true;

    /** Whether the OpenID Connect discovery document exists; without it only the RFC 8414 one does. */
    public bool $openIdDiscovery = true;

    /** Lifetime of access tokens in seconds; null to omit `expires_in` from token responses. */
    public ?int $tokenLifetime = 300;

    /** Whether a refresh returns a new refresh token and retires the old one. */
    public bool $rotateRefreshTokens = false;

    /** Audience of the access tokens issued by the token endpoint. */
    public string $audience = 'api';

    /** @var list<array<string, mixed>> private keys, the current one first */
    private array $keys = [];

    /** @var array<string, array{secret: ?string, jwk: ?array<string, mixed>}> */
    private array $clients = [];

    /** @var array<string, array<string, string>> authorization codes and what they were issued for */
    private array $codes = [];

    /** @var array<string, array{client: string, subject: string, scope: string}> */
    private array $refreshTokens = [];

    /** @var array<string, array{client: string, user_code: string, scope: string, status: string, subject: string, expires: int, slow: bool}> */
    private array $devices = [];

    /** @var array<string, string> access tokens issued by the token endpoint and their subject */
    private array $accessTokens = [];

    /** @var array<string, array<string, mixed>> claims of the users, by subject */
    private array $users = [];

    private int $serial = 0;

    private readonly WebTokenJose $jose;

    /**
     * @param string $algorithm signature algorithm of the tokens; `ES256` keys are the fastest to generate
     */
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        public readonly string $issuer = 'https://idp.example.test',
        private readonly string $algorithm = 'ES256',
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->jose = new WebTokenJose();
        $this->rotateKeys();
    }

    /**
     * Returns the discovery document.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'issuer' => $this->issuer,
            'jwks_uri' => $this->issuer . '/jwks',
            'authorization_endpoint' => $this->issuer . '/authorize',
            'token_endpoint' => $this->issuer . '/token',
            'userinfo_endpoint' => $this->issuer . '/userinfo',
            'device_authorization_endpoint' => $this->issuer . '/device',
            'end_session_endpoint' => $this->issuer . '/logout',
            'id_token_signing_alg_values_supported' => [$this->algorithm],
            'code_challenge_methods_supported' => ['S256'],
        ];
    }

    /**
     * Replaces the signing key, as providers do periodically.
     *
     * @param bool $keepPrevious keeps publishing the previous keys, so that tokens already issued stay verifiable
     */
    public function rotateKeys(bool $keepPrevious = false): void
    {
        $key = KeyFactory::generate($this->algorithm, ['kid' => 'key-' . ++$this->serial]);
        $this->keys = $keepPrevious ? [$key, ...$this->keys] : [$key];
    }

    /**
     * Declares a client. Without secret nor key, it is a public client.
     *
     * @param array<string, mixed>|null $publicJwk public key of a client authenticating with `private_key_jwt`
     */
    public function registerClient(string $clientId, ?string $secret = null, ?array $publicJwk = null): void
    {
        $this->clients[$clientId] = ['secret' => $secret, 'jwk' => $publicJwk];
    }

    /**
     * Sets the claims the UserInfo endpoint and the ID tokens give for a user.
     *
     * @param array<string, mixed> $claims
     */
    public function setUser(string $subject, array $claims): void
    {
        $this->users[$subject] = $claims;
    }

    /**
     * Signs an access token, valid by default: issued now by this provider for the
     * subject `user-1` and the audience `api`, for five minutes, with the `at+jwt` type.
     *
     * @param array<string, mixed> $claims claims to add or replace; a null value removes a default one
     * @param array<string, mixed> $header header members to add or replace, likewise
     */
    public function issueAccessToken(array $claims = [], array $header = []): string
    {
        $now = $this->clock->now()->getTimestamp();

        return $this->sign(
            ['iss' => $this->issuer, 'sub' => 'user-1', 'aud' => $this->audience, 'iat' => $now, 'exp' => $now + 300, ...$claims],
            ['typ' => 'at+jwt', ...$header],
        );
    }

    /**
     * Signs a token with the current key of the provider, or with a key of yours to
     * forge one.
     *
     * @param array<string, mixed>      $claims null values are left out
     * @param array<string, mixed>      $header null values are left out; `kid` defaults to the key used
     * @param array<string, mixed>|null $jwk    private key to sign with instead of the provider's
     */
    public function sign(array $claims, array $header = [], ?array $jwk = null, ?string $algorithm = null): string
    {
        $jwk ??= $this->keys[0];
        $header = ['kid' => $jwk['kid'] ?? null, ...$header];
        $present = static fn(mixed $value): bool => $value !== null;

        return $this->jose->sign(array_filter($claims, $present), array_filter($header, $present), $jwk, $algorithm ?? $this->algorithm);
    }

    /**
     * Plays the user logging in and consenting: takes the authorization URL the client
     * built and returns the query parameters of the callback.
     *
     * @return array<string, string>
     */
    public function authorize(string $authorizationUrl, string $subject = 'user-1'): array
    {
        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
        $parameter = static fn(string $name): string => is_string($query[$name] ?? null) ? $query[$name] : '';
        $callback = ['state' => $parameter('state'), 'iss' => $this->issuer];

        if (!isset($this->clients[$parameter('client_id')]) || $parameter('response_type') !== 'code') {
            return [...$callback, 'error' => 'unauthorized_client'];
        }

        if ($parameter('code_challenge') === '' || $parameter('code_challenge_method') !== 'S256') {
            return [...$callback, 'error' => 'invalid_request', 'error_description' => 'PKCE with S256 is required.'];
        }

        $code = 'code-' . bin2hex(random_bytes(8));
        $this->codes[$code] = [
            'client' => $parameter('client_id'),
            'redirect_uri' => $parameter('redirect_uri'),
            'challenge' => $parameter('code_challenge'),
            'nonce' => $parameter('nonce'),
            'scope' => $parameter('scope'),
            'subject' => $subject,
        ];

        return [...$callback, 'code' => $code];
    }

    /**
     * Plays the user typing the code shown by a device and approving it.
     */
    public function approveDevice(string $userCode, string $subject = 'user-1'): void
    {
        $this->resolveDevice($userCode, 'approved', $subject);
    }

    /**
     * Plays the user refusing a device.
     */
    public function denyDevice(string $userCode): void
    {
        $this->resolveDevice($userCode, 'denied', '');
    }

    /**
     * Makes the next poll of every pending device answer `slow_down`.
     */
    public function askDevicesToSlowDown(): void
    {
        foreach ($this->devices as $code => $device) {
            $this->devices[$code] = [...$device, 'slow' => true];
        }
    }

    /**
     * Ends every session: refresh tokens stop working, as after a logout or a revocation.
     */
    public function revokeRefreshTokens(): void
    {
        $this->refreshTokens = [];
    }

    /**
     * Counts the requests received by an endpoint, such as `/jwks` or `/token`.
     */
    public function requestCount(string $path): int
    {
        return count(array_filter(
            $this->requests,
            fn(RequestInterface $request): bool => (string) $request->getUri()->withQuery('') === $this->issuer . $path,
        ));
    }

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if (!$this->available) {
            throw new class ('Connection refused') extends RuntimeException implements ClientExceptionInterface {};
        }

        $url = (string) $request->getUri()->withQuery('');
        $path = (string) parse_url($this->issuer, PHP_URL_PATH);
        $origin = $path === '' ? $this->issuer : substr($this->issuer, 0, -strlen($path));

        return match (true) {
            $url === $this->issuer . '/.well-known/openid-configuration' && $this->openIdDiscovery,
            $url === $origin . '/.well-known/oauth-authorization-server' . $path => $this->json(200, $this->metadata()),
            $url === $this->issuer . '/jwks' => $this->json(200, ['keys' => array_map(KeyFactory::toPublic(...), $this->keys)]),
            $url === $this->issuer . '/token' && $request->getMethod() === 'POST' => $this->token($request),
            $url === $this->issuer . '/device' && $request->getMethod() === 'POST' => $this->device($request),
            $url === $this->issuer . '/userinfo' => $this->userInfo($request),
            default => $this->json(404, ['message' => 'Not found']),
        };
    }

    private function token(RequestInterface $request): ResponseInterface
    {
        $form = self::form($request);
        $client = $this->authenticate($request, $form);

        if ($client === null) {
            return $this->error(401, 'invalid_client', 'Client authentication failed.');
        }

        $parameter = static fn(string $name): string => $form[$name] ?? '';

        switch ($parameter('grant_type')) {
            case 'client_credentials':
                if ($this->clients[$client]['secret'] === null && $this->clients[$client]['jwk'] === null) {
                    return $this->error(400, 'unauthorized_client', 'A public client cannot use this grant.');
                }

                return $this->json(200, $this->tokens($client, $client, $parameter('scope'), null, false));

            case 'authorization_code':
                $code = $this->codes[$parameter('code')] ?? null;
                unset($this->codes[$parameter('code')]);

                if ($code === null || $code['client'] !== $client || $code['redirect_uri'] !== $parameter('redirect_uri')) {
                    return $this->error(400, 'invalid_grant', 'The authorization code is not valid.');
                }

                if (!hash_equals($code['challenge'], Pkce::challenge($parameter('code_verifier')))) {
                    return $this->error(400, 'invalid_grant', 'The code verifier does not match.');
                }

                return $this->json(200, $this->tokens($client, $code['subject'], $code['scope'], $code['nonce'], true));

            case 'refresh_token':
                $session = $this->refreshTokens[$parameter('refresh_token')] ?? null;

                if ($session === null || $session['client'] !== $client) {
                    return $this->error(400, 'invalid_grant', 'The refresh token is not valid.');
                }

                if ($this->rotateRefreshTokens) {
                    unset($this->refreshTokens[$parameter('refresh_token')]);
                }

                return $this->json(200, array_diff_key(
                    $this->tokens($client, $session['subject'], $parameter('scope') ?: $session['scope'], null, $this->rotateRefreshTokens),
                    ['id_token' => true],
                ));

            case 'urn:ietf:params:oauth:grant-type:device_code':
                $device = $this->devices[$parameter('device_code')] ?? null;

                if ($device === null || $device['client'] !== $client) {
                    return $this->error(400, 'invalid_grant', 'The device code is not valid.');
                }

                if ($device['slow']) {
                    $this->devices[$parameter('device_code')] = [...$device, 'slow' => false];

                    return $this->error(400, 'slow_down');
                }

                if ($device['status'] === 'denied') {
                    return $this->error(400, 'access_denied', 'The user refused.');
                }

                if ($device['expires'] <= $this->clock->now()->getTimestamp()) {
                    return $this->error(400, 'expired_token');
                }

                if ($device['status'] !== 'approved') {
                    return $this->error(400, 'authorization_pending');
                }

                unset($this->devices[$parameter('device_code')]);

                return $this->json(200, $this->tokens($client, $device['subject'], $device['scope'], null, true));

            default:
                return $this->error(400, 'unsupported_grant_type');
        }
    }

    private function device(RequestInterface $request): ResponseInterface
    {
        $form = self::form($request);
        $client = $this->authenticate($request, $form);

        if ($client === null) {
            return $this->error(401, 'invalid_client', 'Client authentication failed.');
        }

        $userCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 4) . '-' . substr(bin2hex(random_bytes(4)), 0, 4));
        $deviceCode = 'device-' . bin2hex(random_bytes(16));
        $this->devices[$deviceCode] = [
            'client' => $client,
            'user_code' => $userCode,
            'scope' => $form['scope'] ?? '',
            'status' => 'pending',
            'subject' => '',
            'expires' => $this->clock->now()->getTimestamp() + 600,
            'slow' => false,
        ];

        return $this->json(200, [
            'device_code' => $deviceCode,
            'user_code' => $userCode,
            'verification_uri' => $this->issuer . '/activate',
            'verification_uri_complete' => $this->issuer . '/activate?user_code=' . $userCode,
            'expires_in' => 600,
            'interval' => 5,
        ]);
    }

    private function userInfo(RequestInterface $request): ResponseInterface
    {
        $authorization = $request->getHeaderLine('Authorization');
        $token = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
        $subject = $this->accessTokens[$token] ?? null;

        if ($subject === null) {
            return $this->error(401, 'invalid_token', 'The access token is not valid.')
                ->withHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
        }

        return $this->json(200, [...($this->users[$subject] ?? []), 'sub' => $subject]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tokens(string $client, string $subject, string $scope, ?string $nonce, bool $withRefreshToken): array
    {
        $now = $this->clock->now()->getTimestamp();
        $lifetime = $this->tokenLifetime ?? 300;
        $accessToken = $this->issueAccessToken([
            'sub' => $subject,
            'client_id' => $client,
            'scope' => $scope === '' ? null : $scope,
            'exp' => $now + $lifetime,
            'jti' => bin2hex(random_bytes(8)),
        ]);
        $this->accessTokens[$accessToken] = $subject;
        $response = ['access_token' => $accessToken, 'token_type' => 'Bearer', 'scope' => $scope];

        if ($this->tokenLifetime !== null) {
            $response['expires_in'] = $this->tokenLifetime;
        }

        if ($withRefreshToken) {
            $refreshToken = 'refresh-' . bin2hex(random_bytes(16));
            $this->refreshTokens[$refreshToken] = ['client' => $client, 'subject' => $subject, 'scope' => $scope];
            $response['refresh_token'] = $refreshToken;
        }

        if (in_array('openid', explode(' ', $scope), true)) {
            $digest = hash('sha256', $accessToken, true);
            $response['id_token'] = $this->sign([
                ...($this->users[$subject] ?? []),
                'iss' => $this->issuer,
                'sub' => $subject,
                'aud' => $client,
                'iat' => $now,
                'exp' => $now + $lifetime,
                'nonce' => $nonce === '' ? null : $nonce,
                'at_hash' => Pkce::base64Url(substr($digest, 0, 16)),
            ], ['typ' => 'JWT']);
        }

        return $response;
    }

    /**
     * Identifies the client of a request, checking its credentials.
     *
     * @param array<string, string> $form
     */
    private function authenticate(RequestInterface $request, array $form): ?string
    {
        $authorization = $request->getHeaderLine('Authorization');

        if (str_starts_with($authorization, 'Basic ')) {
            [$id, $secret] = [...array_map(urldecode(...), explode(':', (string) base64_decode(substr($authorization, 6), true), 2)), ''];

            return isset($this->clients[$id]) && $this->clients[$id]['secret'] !== null && hash_equals($this->clients[$id]['secret'], $secret)
                ? $id
                : null;
        }

        $id = $form['client_id'] ?? '';
        $client = $this->clients[$id] ?? null;

        if ($client === null) {
            return null;
        }

        if (isset($form['client_assertion'])) {
            $claims = json_decode((string) base64_decode(strtr(explode('.', $form['client_assertion'])[1] ?? '', '-_', '+/'), true), true);
            $valid = $client['jwk'] !== null
                && ($form['client_assertion_type'] ?? '') === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
                && is_array($claims)
                && ($claims['iss'] ?? null) === $id
                && ($claims['sub'] ?? null) === $id
                && in_array($claims['aud'] ?? null, [$this->issuer, $this->issuer . '/token'], true)
                && is_int($claims['exp'] ?? null)
                && $claims['exp'] > $this->clock->now()->getTimestamp()
                && array_any(
                    ['RS256', 'PS256', 'ES256', 'EdDSA'],
                    fn(string $algorithm): bool => $this->jose->verify($form['client_assertion'], $client['jwk'], $algorithm),
                );

            return $valid ? $id : null;
        }

        if ($client['jwk'] !== null) {
            return null;
        }

        return $client['secret'] === null || hash_equals($client['secret'], $form['client_secret'] ?? '') ? $id : null;
    }

    private function resolveDevice(string $userCode, string $status, string $subject): void
    {
        foreach ($this->devices as $code => $device) {
            if ($device['user_code'] === $userCode) {
                $this->devices[$code] = [...$device, 'status' => $status, 'subject' => $subject];

                return;
            }
        }

        throw new RuntimeException(sprintf('No device is waiting with the user code "%s".', $userCode));
    }

    /**
     * @return array<string, string>
     */
    private static function form(RequestInterface $request): array
    {
        parse_str((string) $request->getBody(), $parsed);
        $form = [];

        foreach ($parsed as $name => $value) {
            if (is_string($value)) {
                $form[(string) $name] = $value;
            }
        }

        return $form;
    }

    private function error(int $status, string $error, ?string $description = null): ResponseInterface
    {
        return $this->json($status, ['error' => $error, ...($description === null ? [] : ['error_description' => $description])]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
    }
}
