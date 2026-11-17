<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Fixture;

use Acta\Http\Exception\HttpException;
use Acta\Http\OpenApi\SecurityScheme;
use Acta\Http\Security\Authenticator;
use Override;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Authenticator for the tests: a token is known or not, and grants a fixed list of scopes.
 */
final class TokenAuthenticator implements Authenticator
{
    /** @var list<array{string, list<string>}> scheme name and scopes of each call */
    public array $calls = [];

    /**
     * @param string                      $header header carrying the credentials
     * @param array<string, list<string>> $tokens scopes granted to each known credential
     */
    public function __construct(
        private readonly string $header,
        private readonly array $tokens,
    ) {}

    #[Override]
    public function authenticate(ServerRequestInterface $request, SecurityScheme $scheme, array $scopes): ServerRequestInterface
    {
        $this->calls[] = [$scheme->name, $scopes];
        $credentials = $request->getHeaderLine($this->header);
        $granted = $this->tokens[$credentials] ?? throw HttpException::unauthorized(
            $credentials === '' ? 'Credentials are required.' : 'The credentials are not valid.',
            $scheme->scheme === 'bearer' ? 'Bearer error="invalid_token"' : 'ApiKey',
        );
        $missing = array_diff($scopes, $granted);

        if ($missing !== []) {
            throw HttpException::forbidden(
                sprintf('Missing scope: %s.', implode(' ', $missing)),
                sprintf('Bearer error="insufficient_scope", scope="%s"', implode(' ', $scopes)),
            );
        }

        return $request->withAttribute('subject:' . $scheme->name, $credentials);
    }
}
