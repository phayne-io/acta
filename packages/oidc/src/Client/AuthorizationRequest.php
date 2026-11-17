<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

use SensitiveParameter;

/**
 * A login in progress: where to send the user, and what must be remembered until the
 * provider sends them back.
 *
 * Keep it in the session of the user between the redirect and the callback, with
 * {@see self::toArray()} and {@see self::fromArray()}. The code verifier is a secret.
 */
final readonly class AuthorizationRequest
{
    /**
     * @param string      $url          URL of the provider to redirect the user to
     * @param string      $state        value the callback must return, binding it to this request
     * @param string|null $nonce        value the ID token must contain; null when no ID token was asked for
     * @param string      $codeVerifier PKCE secret proving that who redeems the code is who asked for it
     */
    public function __construct(
        public string $url,
        public string $state,
        public ?string $nonce,
        #[SensitiveParameter]
        public string $codeVerifier,
        public string $redirectUri,
    ) {}

    /**
     * @param array<mixed> $values
     */
    public static function fromArray(array $values): ?self
    {
        $string = static fn(string $name): ?string => is_string($values[$name] ?? null) ? $values[$name] : null;
        [$url, $state, $verifier, $redirectUri] = [$string('url'), $string('state'), $string('code_verifier'), $string('redirect_uri')];

        return $url === null || $state === null || $verifier === null || $redirectUri === null
            ? null
            : new self($url, $state, $string('nonce'), $verifier, $redirectUri);
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'state' => $this->state,
            'nonce' => $this->nonce,
            'code_verifier' => $this->codeVerifier,
            'redirect_uri' => $this->redirectUri,
        ];
    }
}
