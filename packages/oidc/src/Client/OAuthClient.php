<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

use Acta\Oidc\Discovery\MetadataSource;
use Acta\Oidc\Exception\AuthorizationFailed;
use Acta\Oidc\Exception\ConfigurationError;
use Acta\Oidc\Exception\InvalidToken;
use Acta\Oidc\Exception\ProviderError;
use Acta\Oidc\Exception\ProviderUnreachable;
use Acta\Oidc\Http\Transport;
use Acta\Oidc\Jwks\JwksProvider;
use Acta\Oidc\SystemClock;
use Acta\Oidc\Token\Claims;
use Acta\Oidc\Token\JwtVerifier;
use Acta\Oidc\Token\TokenError;
use Closure;
use NoDiscard;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * An OAuth 2.0 / OpenID Connect client: obtains tokens from the provider.
 *
 * | Grant | Methods |
 * | --- | --- |
 * | Client credentials | {@see self::clientCredentials()} |
 * | Authorization code with PKCE | {@see self::authorizationRequest()}, then {@see self::exchangeCode()} |
 * | Device authorization | {@see self::requestDeviceAuthorization()}, then {@see self::pollDeviceToken()} |
 * | Refresh token | {@see self::refresh()} |
 *
 * ID tokens are always verified before being returned: signature, issuer, audience,
 * validity period, nonce and access token hash.
 */
final readonly class OAuthClient
{
    private const string DEVICE_GRANT = 'urn:ietf:params:oauth:grant-type:device_code';

    private JwtVerifier $idTokens;

    /**
     * @param JwtVerifier|null $idTokens verifier of ID tokens; by default one reading the keys of the
     *                                   provider with an in-memory cache. Pass your own to share a cache
     *                                   or to change the accepted algorithms
     */
    public function __construct(
        public string $clientId,
        private ClientAuthentication $authentication,
        private MetadataSource $provider,
        private Transport $transport,
        ?JwtVerifier $idTokens = null,
        private ClockInterface $clock = new SystemClock(),
    ) {
        $this->idTokens = $idTokens ?? new JwtVerifier($provider, new JwksProvider($provider, $transport), clock: $clock);
    }

    /**
     * Returns the issuer the client is registered with.
     *
     * @throws ProviderUnreachable when the metadata of the provider cannot be fetched
     */
    public function issuer(): string
    {
        return $this->provider->metadata()->issuer;
    }

    /**
     * Obtains a token for the client itself, to call another service.
     *
     * @param list<string>          $scopes
     * @param array<string, string> $parameters additional parameters, such as the `audience` or `resource` some providers need
     *
     * @throws ProviderError       when the provider refuses
     * @throws ProviderUnreachable when the provider cannot be reached
     */
    #[NoDiscard]
    public function clientCredentials(array $scopes = [], array $parameters = []): TokenSet
    {
        return $this->token([
            ...$parameters,
            'grant_type' => 'client_credentials',
            ...($scopes === [] ? [] : ['scope' => implode(' ', $scopes)]),
        ]);
    }

    /**
     * Starts a login: returns where to redirect the user and what to remember meanwhile.
     *
     * A `state`, a PKCE code verifier and, when `openid` is among the scopes, a `nonce`
     * are generated for this request alone.
     *
     * @param string                $redirectUri where the provider sends the user back; must be registered for the client
     * @param list<string>          $scopes
     * @param array<string, string> $parameters  additional parameters, such as `prompt`, `login_hint` or `audience`
     *
     * @throws ConfigurationError  when the provider has no authorization endpoint
     * @throws ProviderUnreachable when the metadata of the provider cannot be fetched
     */
    #[NoDiscard]
    public function authorizationRequest(string $redirectUri, array $scopes = ['openid'], array $parameters = []): AuthorizationRequest
    {
        $state = Pkce::base64Url(random_bytes(24));
        $nonce = in_array('openid', $scopes, true) ? Pkce::base64Url(random_bytes(24)) : null;
        $verifier = Pkce::verifier();
        $endpoint = $this->provider->metadata()->endpoint('authorizationEndpoint');
        $query = http_build_query([
            ...$parameters,
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $scopes),
            'state' => $state,
            ...($nonce === null ? [] : ['nonce' => $nonce]),
            'code_challenge' => Pkce::challenge($verifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return new AuthorizationRequest(
            $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $query,
            $state,
            $nonce,
            $verifier,
            $redirectUri,
        );
    }

    /**
     * Completes a login with the parameters the provider sent back to the redirect URI.
     *
     * @param AuthorizationRequest $request  the request the callback answers, as kept in the session
     * @param array<mixed>         $callback query parameters of the callback
     *
     * @throws AuthorizationFailed when the user refused, or the callback does not answer this request
     * @throws InvalidToken        when the ID token is refused
     * @throws ProviderError       when the provider refuses the code
     * @throws ProviderUnreachable when the provider cannot be reached
     */
    #[NoDiscard]
    public function exchangeCode(AuthorizationRequest $request, array $callback): TokenSet
    {
        $parameter = static fn(string $name): ?string => is_string($callback[$name] ?? null) ? $callback[$name] : null;

        // The state is checked first: nothing in a callback that fails this check can be trusted.
        if (!hash_equals($request->state, $parameter('state') ?? '')) {
            throw new AuthorizationFailed('invalid_callback', 'The callback does not belong to this login.');
        }

        if ($parameter('error') !== null) {
            throw new AuthorizationFailed($parameter('error'), $parameter('error_description'));
        }

        // RFC 9207: when the provider identifies itself in the callback, it must be the expected one.
        if ($parameter('iss') !== null && $parameter('iss') !== $this->issuer()) {
            throw new AuthorizationFailed('invalid_callback', 'The callback comes from another provider.');
        }

        $tokens = $this->token([
            'grant_type' => 'authorization_code',
            'code' => $parameter('code') ?? throw new AuthorizationFailed('invalid_callback', 'The callback carries no authorization code.'),
            'redirect_uri' => $request->redirectUri,
            'code_verifier' => $request->codeVerifier,
        ]);

        if ($request->nonce === null) {
            return $tokens;
        }

        if ($tokens->idToken === null) {
            throw new InvalidToken(TokenError::MissingClaim, 'The provider did not return the ID token that was asked for.');
        }

        return $tokens->withIdTokenClaims($this->verifyIdToken($tokens, $request->nonce));
    }

    /**
     * Exchanges a refresh token for new tokens.
     *
     * The result keeps the refresh token when the provider did not rotate it.
     *
     * @param list<string> $scopes a subset of the scopes originally granted; the same ones when empty
     *
     * @throws ConfigurationError  when a token set without refresh token is given
     * @throws InvalidToken        when the new ID token is refused
     * @throws ProviderError       when the provider refuses, typically with `invalid_grant` once the session ended
     * @throws ProviderUnreachable when the provider cannot be reached
     */
    #[NoDiscard]
    public function refresh(#[SensitiveParameter] TokenSet|string $refreshToken, array $scopes = []): TokenSet
    {
        $previous = $refreshToken instanceof TokenSet ? $refreshToken : new TokenSet('', refreshToken: $refreshToken);
        $tokens = $previous->refreshedBy($this->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $previous->refreshToken ?? throw new ConfigurationError('This token set has no refresh token.'),
            ...($scopes === [] ? [] : ['scope' => implode(' ', $scopes)]),
        ]));

        return $tokens->idToken === null ? $tokens : $tokens->withIdTokenClaims($this->verifyIdToken($tokens, null));
    }

    /**
     * Asks the UserInfo endpoint for the claims of the user an access token speaks for.
     *
     * Given a token set with a verified ID token, the answer must describe the same
     * subject, as OpenID Connect requires.
     *
     * @throws ConfigurationError  when the provider has no UserInfo endpoint
     * @throws InvalidToken        when the answer describes another subject
     * @throws ProviderError       when the provider refuses the token
     * @throws ProviderUnreachable when the provider cannot be reached, or answers a signed response, which is not supported
     */
    #[NoDiscard]
    public function userInfo(#[SensitiveParameter] TokenSet|string $accessToken): Claims
    {
        $token = $accessToken instanceof TokenSet ? $accessToken->accessToken : $accessToken;
        $claims = new Claims($this->transport->get(
            $this->provider->metadata()->endpoint('userinfoEndpoint'),
            ['Authorization' => 'Bearer ' . $token],
        ));
        $expected = $accessToken instanceof TokenSet ? $accessToken->idTokenClaims?->string('sub') : null;

        if ($claims->string('sub') === null || ($expected !== null && $claims->string('sub') !== $expected)) {
            throw new InvalidToken(TokenError::WrongSubject, 'The UserInfo response does not describe the user who logged in.');
        }

        return $claims;
    }

    /**
     * Starts a device authorization (RFC 8628), for clients without browser.
     *
     * Show the user the verification URI and the user code, then call {@see self::pollDeviceToken()}.
     *
     * @param list<string>          $scopes
     * @param array<string, string> $parameters additional parameters, such as `audience`
     *
     * @throws ConfigurationError  when the provider has no device authorization endpoint
     * @throws ProviderError       when the provider refuses
     * @throws ProviderUnreachable when the provider cannot be reached
     */
    #[NoDiscard]
    public function requestDeviceAuthorization(array $scopes = [], array $parameters = []): DeviceAuthorization
    {
        $metadata = $this->provider->metadata();
        [$form, $headers] = $this->authentication->authenticate(
            $this->clientId,
            [...$parameters, ...($scopes === [] ? [] : ['scope' => implode(' ', $scopes)])],
            $metadata,
        );
        $response = $this->transport->post($metadata->endpoint('deviceAuthorizationEndpoint'), $form, $headers);
        $string = static fn(string $name): ?string => is_string($response[$name] ?? null) && $response[$name] !== ''
            ? $response[$name]
            : null;
        $lifetime = is_numeric($response['expires_in'] ?? null) ? (int) $response['expires_in'] : 600;
        $interval = is_numeric($response['interval'] ?? null) ? (int) $response['interval'] : 5;

        return new DeviceAuthorization(
            $string('device_code') ?? throw new ProviderUnreachable('The device authorization response has no device code.'),
            $string('user_code') ?? throw new ProviderUnreachable('The device authorization response has no user code.'),
            $string('verification_uri') ?? $string('verification_url')
                ?? throw new ProviderUnreachable('The device authorization response has no verification URI.'),
            $string('verification_uri_complete'),
            $this->clock->now()->modify(sprintf('+%d seconds', $lifetime)),
            max(1, $interval),
        );
    }

    /**
     * Asks once whether the user has completed a device authorization.
     *
     * @return TokenSet|DeviceAuthorization the tokens, or the authorization to keep polling
     *                                      with, whose interval grows when the provider asks to slow down
     *
     * @throws AuthorizationFailed when the user refused or the codes expired
     * @throws InvalidToken        when the ID token is refused
     * @throws ProviderError       when the provider refuses for another reason
     * @throws ProviderUnreachable when the provider cannot be reached
     */
    #[NoDiscard]
    public function checkDeviceAuthorization(DeviceAuthorization $device): TokenSet|DeviceAuthorization
    {
        try {
            $tokens = $this->token(['grant_type' => self::DEVICE_GRANT, 'device_code' => $device->deviceCode]);
        } catch (ProviderError $error) {
            return match ($error->error) {
                'authorization_pending' => $device,
                'slow_down' => $device->slowedDown(),
                'access_denied', 'expired_token' => throw new AuthorizationFailed($error->error, $error->description),
                default => throw $error,
            };
        }

        return $tokens->idToken === null ? $tokens : $tokens->withIdTokenClaims($this->verifyIdToken($tokens, null));
    }

    /**
     * Waits until the user has completed a device authorization, polling the provider
     * at the pace it asks for.
     *
     * @param Closure(int): mixed $wait pauses for the given number of seconds; `sleep()` by default
     *
     * @throws AuthorizationFailed when the user refused or the codes expired
     * @throws InvalidToken        when the ID token is refused
     * @throws ProviderError       when the provider refuses for another reason
     * @throws ProviderUnreachable when the provider cannot be reached
     */
    #[NoDiscard]
    public function pollDeviceToken(DeviceAuthorization $device, Closure $wait = sleep(...)): TokenSet
    {
        while ($this->clock->now() < $device->expiresAt) {
            $wait($device->interval);
            $result = $this->checkDeviceAuthorization($device);

            if ($result instanceof TokenSet) {
                return $result;
            }

            $device = $result;
        }

        throw new AuthorizationFailed('expired_token', 'The device authorization was not completed in time.');
    }

    /**
     * Returns the URL ending the session of the user at the provider (RP-initiated logout).
     *
     * @param string|null $idToken               the ID token of the session, which lets the provider skip a confirmation
     * @param string|null $postLogoutRedirectUri where to send the user afterwards; must be registered for the client
     *
     * @throws ConfigurationError  when the provider has no end-session endpoint
     * @throws ProviderUnreachable when the metadata of the provider cannot be fetched
     */
    #[NoDiscard]
    public function logoutUrl(?string $idToken = null, ?string $postLogoutRedirectUri = null, ?string $state = null): string
    {
        $endpoint = $this->provider->metadata()->endpoint('endSessionEndpoint');
        $query = http_build_query(array_filter([
            'client_id' => $this->clientId,
            'id_token_hint' => $idToken,
            'post_logout_redirect_uri' => $postLogoutRedirectUri,
            'state' => $state,
        ], static fn(?string $value): bool => $value !== null), '', '&', PHP_QUERY_RFC3986);

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $query;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function token(array $parameters): TokenSet
    {
        $metadata = $this->provider->metadata();
        [$form, $headers] = $this->authentication->authenticate($this->clientId, $parameters, $metadata);

        return TokenSet::fromResponse(
            $this->transport->post($metadata->endpoint('tokenEndpoint'), $form, $headers),
            $this->clock->now(),
        );
    }

    /**
     * Verifies the ID token of a token response (OpenID Connect Core, section 3.1.3.7).
     */
    private function verifyIdToken(TokenSet $tokens, ?string $nonce): Claims
    {
        $jwt = $this->idTokens->verify((string) $tokens->idToken, [$this->clientId]);
        $claims = $jwt->claims;

        if ($claims->string('sub') === null) {
            throw new InvalidToken(TokenError::MissingClaim, 'The ID token names no subject.');
        }

        $authorizedParty = $claims->string('azp');

        if (($authorizedParty !== null && $authorizedParty !== $this->clientId) || ($authorizedParty === null && count($claims->strings('aud')) > 1)) {
            throw new InvalidToken(TokenError::WrongParty, 'The ID token was issued to another client.');
        }

        if ($nonce !== null && !hash_equals($nonce, $claims->string('nonce') ?? '')) {
            throw new InvalidToken(TokenError::WrongNonce, 'The ID token does not answer this login.');
        }

        $hash = $claims->string('at_hash');

        if ($hash !== null) {
            $digest = hash(match (substr($jwt->algorithm(), -3)) {
                '384' => 'sha384',
                '512', 'DSA' => 'sha512',
                default => 'sha256',
            }, $tokens->accessToken, true);

            if (!hash_equals(Pkce::base64Url(substr($digest, 0, intdiv(strlen($digest), 2))), $hash)) {
                throw new InvalidToken(TokenError::WrongHash, 'The ID token does not match the access token it came with.');
            }
        }

        return $claims;
    }
}
