<?php

declare(strict_types=1);

namespace Acta\Oidc\Server;

use Acta\Oidc\Exception\InvalidToken;
use Acta\Oidc\Exception\ProviderUnreachable;
use Acta\Oidc\Token\AccessTokenVerifier;
use Acta\Oidc\Token\Principal;
use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Protects what follows it with Bearer JWT access tokens.
 *
 * A request with a valid token reaches the handler with a {@see Principal} attribute.
 * The others are answered as RFC 6750 specifies: 401 without token or with a refused
 * one, 403 when the token lacks a required scope, each with its `WWW-Authenticate`
 * header and a small problem document (RFC 9457). When the provider cannot be reached
 * to fetch its keys, the answer is 503: the token may well be valid.
 *
 * Use this middleware to protect a whole pipeline with the same scopes. When the
 * requirements differ per operation, call {@see AccessTokenVerifier} from whatever
 * knows them.
 */
final readonly class BearerAuthMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $scopes scopes every request must have been granted
     * @param string|null  $realm  protection space named in the challenges
     */
    public function __construct(
        private AccessTokenVerifier $verifier,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private array $scopes = [],
        private ?string $realm = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = BearerToken::fromRequest($request);

        if ($token === null) {
            return $this->refuse(401, BearerChallenge::missingToken($this->realm), 'A Bearer access token is required.');
        }

        try {
            $principal = $this->verifier->verify($token);
        } catch (InvalidToken $exception) {
            $this->logger->info('Refused an access token: {reason}.', ['reason' => $exception->reason->value, 'exception' => $exception]);

            return $this->refuse(401, BearerChallenge::invalidToken($exception->getMessage(), $this->realm), $exception->getMessage());
        } catch (ProviderUnreachable $exception) {
            $this->logger->error('Access tokens cannot be verified: {reason}', ['reason' => $exception->getMessage(), 'exception' => $exception]);

            return $this->refuse(503, null, 'Access tokens cannot be verified at the moment.');
        }

        $missing = $principal->missingScopes($this->scopes);

        if ($missing !== []) {
            $this->logger->info('Refused an access token of {subject}: missing scope(s) {scopes}.', [
                'subject' => $principal->subject,
                'scopes' => implode(' ', $missing),
            ]);

            return $this->refuse(403, BearerChallenge::insufficientScope($this->scopes, $this->realm), 'The access token lacks a required scope.');
        }

        return $handler->handle($request->withAttribute(Principal::class, $principal));
    }

    private function refuse(int $status, ?string $challenge, string $detail): ResponseInterface
    {
        $title = [401 => 'Unauthorized', 403 => 'Forbidden', 503 => 'Service Unavailable'][$status];
        $problem = ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail];
        $response = $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/problem+json')
            ->withBody($this->streams->createStream(json_encode($problem, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));

        return $challenge === null ? $response : $response->withHeader('WWW-Authenticate', $challenge);
    }
}
