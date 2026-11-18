<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Unit\Idempotency;

use Acta\Http\Exception\HttpException;
use Acta\Http\Idempotency\IdempotencyMiddleware;
use Acta\Http\Idempotency\InMemoryIdempotencyStore;
use Acta\Http\Testing\Pipeline;
use Acta\Http\Tests\Fixture\FrozenClock;
use Acta\Http\Tests\HttpTestCase;
use Closure;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\StreamFactory;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class IdempotencyMiddlewareTest extends HttpTestCase
{
    private FrozenClock $clock;

    private InMemoryIdempotencyStore $store;

    private int $handled = 0;

    #[Override]
    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->store = new InMemoryIdempotencyStore();
    }

    public function testARepeatedRequestIsAnsweredWithTheFirstResponse(): void
    {
        $pipeline = $this->pipeline(fn(): ResponseInterface => self::responder()->created('/accounts/' . $this->handled, ['number' => $this->handled]));
        $request = self::request('POST', '/accounts', ['Idempotency-Key' => 'key-1'], ['owner' => 'Ada']);

        $first = $pipeline->handle($request);
        $second = $pipeline->handle($request);

        self::assertSame(1, $this->handled);
        self::assertSame(201, $second->getStatusCode());
        self::assertSame('/accounts/1', $second->getHeaderLine('Location'));
        self::assertSame('application/json', $second->getHeaderLine('Content-Type'));
        self::assertSame(['number' => 1], self::json($second));
        self::assertSame('true', $second->getHeaderLine('Idempotency-Replayed'));
        self::assertFalse($first->hasHeader('Idempotency-Replayed'));
        // The first response is still readable after having been stored.
        self::assertSame(['number' => 1], self::json($first));
    }

    public function testAResponseWithoutBodyIsReplayed(): void
    {
        $pipeline = $this->pipeline(static fn(): ResponseInterface => self::responder()->empty());
        $request = self::request('POST', '/accounts', ['Idempotency-Key' => '"quoted-key"']);

        $pipeline->handle($request);
        $replayed = $pipeline->handle($request->withHeader('Idempotency-Key', 'quoted-key'));

        self::assertSame(1, $this->handled);
        self::assertSame(204, $replayed->getStatusCode());
        self::assertSame('', (string) $replayed->getBody());
    }

    public function testRequestsWithoutKeyOrWithAnotherMethodAreAlwaysHandled(): void
    {
        $pipeline = $this->pipeline(static fn(): ResponseInterface => self::responder()->empty());

        $pipeline->handle(self::request('POST', '/accounts'));
        $pipeline->handle(self::request('POST', '/accounts'));
        $pipeline->handle(self::request('PUT', '/accounts/1', ['Idempotency-Key' => 'key-1']));
        $pipeline->handle(self::request('PUT', '/accounts/1', ['Idempotency-Key' => 'key-1']));

        self::assertSame(4, $this->handled);
    }

    public function testTheMethodsAreConfigurable(): void
    {
        $pipeline = $this->pipeline(static fn(): ResponseInterface => self::responder()->empty(), methods: ['POST', 'PATCH']);

        $pipeline->handle(self::request('PATCH', '/accounts/1', ['Idempotency-Key' => 'key-1']));
        $pipeline->handle(self::request('PATCH', '/accounts/1', ['Idempotency-Key' => 'key-1']));

        self::assertSame(1, $this->handled);
    }

    public function testAKeyReusedForAnotherRequestIsRejected(): void
    {
        $pipeline = $this->pipeline(static fn(): ResponseInterface => self::responder()->empty());
        $pipeline->handle(self::request('POST', '/accounts', ['Idempotency-Key' => 'key-1'], ['owner' => 'Ada']));

        foreach ([
            self::request('POST', '/accounts', ['Idempotency-Key' => 'key-1'], ['owner' => 'Grace']),
            self::request('POST', '/accounts?dry-run=1', ['Idempotency-Key' => 'key-1'], ['owner' => 'Ada']),
            self::request('POST', '/transfers', ['Idempotency-Key' => 'key-1'], ['owner' => 'Ada']),
        ] as $request) {
            try {
                $pipeline->handle($request);
                self::fail('The key should be refused.');
            } catch (HttpException $exception) {
                self::assertSame(422, $exception->status);
                self::assertSame('This Idempotency-Key was already used for a different request.', $exception->getMessage());
            }
        }

        self::assertSame(1, $this->handled);
    }

    public function testAKeyStillBeingHandledIsAConflict(): void
    {
        $request = self::request('POST', '/accounts', ['Idempotency-Key' => 'key-1']);
        $retries = $this->pipeline(static fn(): ResponseInterface => self::fail('The concurrent request should be refused.'));
        $pipeline = $this->pipeline(static function () use ($retries, $request): ResponseInterface {
            try {
                // The client retries while the first request is still running.
                $retries->handle($request);
            } catch (HttpException $exception) {
                self::assertSame(409, $exception->status);
            }

            return self::responder()->empty();
        });

        $pipeline->handle($request);

        self::assertSame(1, $this->handled);
    }

    public function testFailuresAreNotStoredSoThatTheClientCanRetry(): void
    {
        $outcomes = [
            static fn() => throw new RuntimeException('boom'),
            static fn(): ResponseInterface => self::responder()->empty(503),
            static fn(): ResponseInterface => self::responder()->json(['ok' => true]),
        ];
        $pipeline = $this->pipeline(function () use (&$outcomes): ResponseInterface {
            $outcome = array_shift($outcomes) ?? throw new RuntimeException('No outcome left.');

            return $outcome();
        });
        $request = self::request('POST', '/accounts', ['Idempotency-Key' => 'key-1']);

        try {
            $pipeline->handle($request);
            self::fail('The failure of the handler should be rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertSame(503, $pipeline->handle($request)->getStatusCode());
        self::assertSame(200, $pipeline->handle($request)->getStatusCode());
        self::assertSame(200, $pipeline->handle($request)->getStatusCode());
        self::assertSame(3, $this->handled);
    }

    public function testClientErrorsAreStoredLikeAnyAnswer(): void
    {
        $pipeline = $this->pipeline(static fn(): ResponseInterface => self::responder()->json(['error' => true], 409));
        $request = self::request('POST', '/accounts', ['Idempotency-Key' => 'key-1']);

        $pipeline->handle($request);

        self::assertSame(409, $pipeline->handle($request)->getStatusCode());
        self::assertSame(1, $this->handled);
    }

    public function testAKeyIsForgottenAfterItsTimeToLive(): void
    {
        $pipeline = $this->pipeline(static fn(): ResponseInterface => self::responder()->empty(), ttl: 60);
        $request = self::request('POST', '/accounts', ['Idempotency-Key' => 'key-1']);

        $pipeline->handle($request);
        $this->clock->advance('+59 seconds');
        $pipeline->handle($request);

        self::assertSame(1, $this->handled);

        $this->clock->advance('+1 second');
        $pipeline->handle($request);

        self::assertSame(2, $this->handled);
    }

    public function testKeysCanBeScopedToTheCaller(): void
    {
        $pipeline = $this->pipeline(
            static fn(): ResponseInterface => self::responder()->empty(),
            scope: static fn(ServerRequestInterface $request): string => $request->getHeaderLine('Authorization'),
        );

        $pipeline->handle(self::request('POST', '/accounts', ['Idempotency-Key' => 'key-1', 'Authorization' => 'Bearer ada']));
        $pipeline->handle(self::request('POST', '/accounts', ['Idempotency-Key' => 'key-1', 'Authorization' => 'Bearer grace']));
        $pipeline->handle(self::request('POST', '/accounts', ['Idempotency-Key' => 'key-1', 'Authorization' => 'Bearer ada']));

        self::assertSame(2, $this->handled);
    }

    public function testAnUnusableKeyIsABadRequest(): void
    {
        $pipeline = $this->pipeline(static fn(): ResponseInterface => self::responder()->empty());

        foreach (['""', str_repeat('k', 256)] as $key) {
            try {
                $pipeline->handle(self::request('POST', '/accounts', ['Idempotency-Key' => $key]));
                self::fail('The key should be refused.');
            } catch (HttpException $exception) {
                self::assertSame(400, $exception->status);
            }
        }

        self::assertSame(0, $this->handled);
    }

    /**
     * @param Closure(): ResponseInterface                   $answer
     * @param list<string>                                   $methods
     * @param (Closure(ServerRequestInterface): string)|null $scope
     */
    private function pipeline(Closure $answer, int $ttl = 86400, array $methods = ['POST'], ?Closure $scope = null): Pipeline
    {
        $handler = new class ($answer, $this->handled) implements RequestHandlerInterface {
            /**
             * @param Closure(): ResponseInterface $answer
             */
            public function __construct(private readonly Closure $answer, private int &$handled) {}

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->handled;

                return ($this->answer)();
            }
        };

        return new Pipeline($handler, new IdempotencyMiddleware(
            $this->store,
            new ResponseFactory(),
            new StreamFactory(),
            $this->clock,
            $ttl,
            $methods,
            $scope,
        ));
    }
}
