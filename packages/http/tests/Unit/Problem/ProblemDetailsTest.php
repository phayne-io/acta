<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Unit\Problem;

use Acta\Http\Exception\ContractViolation;
use Acta\Http\Exception\HttpException;
use Acta\Http\Pipeline;
use Acta\Http\Problem\ExceptionMapper;
use Acta\Http\Problem\ProblemDetails;
use Acta\Http\Problem\ProblemDetailsMiddleware;
use Acta\Http\Tests\Fixture\RecordingLogger;
use Acta\Http\Tests\HttpTestCase;
use Acta\Http\Trace\CorrelationMiddleware;
use Acta\Http\Validation\Violation;
use DomainException;
use LogicException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

final class ProblemDetailsTest extends HttpTestCase
{
    public function testAProblemDocumentHoldsTheStandardMembersThenItsExtensions(): void
    {
        $problem = new ProblemDetails(
            403,
            'Insufficient funds',
            'The balance is 30.00 EUR.',
            'https://example.test/problems/insufficient-funds',
            '/accounts/1/withdrawals/7',
            ['balance' => 3000, 'status' => 'ignored: standard members cannot be replaced'],
        );

        self::assertSame(
            [
                'type' => 'https://example.test/problems/insufficient-funds',
                'title' => 'Insufficient funds',
                'status' => 403,
                'detail' => 'The balance is 30.00 EUR.',
                'instance' => '/accounts/1/withdrawals/7',
                'balance' => 3000,
            ],
            $problem->toArray(),
        );
        self::assertSame(['type' => 'about:blank', 'title' => 'Gone', 'status' => 410], new ProblemDetails(410, 'Gone')->jsonSerialize());

        $extended = $problem->with('currency', 'EUR');

        self::assertSame('EUR', $extended->extensions['currency']);
        self::assertArrayNotHasKey('currency', $problem->extensions);
    }

    public function testAnHttpExceptionDescribesItself(): void
    {
        self::assertSame(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404], HttpException::notFound()->problem()->toArray());
        self::assertSame('Conflict', HttpException::conflict()->getMessage());
        self::assertSame('Already open.', HttpException::conflict('Already open.')->problem()->detail);
        self::assertSame(['Allow' => 'GET, POST'], HttpException::methodNotAllowed(['GET', 'POST'])->headers);
        self::assertSame(['WWW-Authenticate' => 'Bearer'], HttpException::unauthorized()->headers);
        self::assertSame([], HttpException::forbidden()->headers);
        self::assertSame(412, HttpException::preconditionFailed()->status);
        self::assertSame(415, HttpException::unsupportedMediaType()->status);
        self::assertSame(422, HttpException::unprocessable()->status);
        self::assertSame(400, HttpException::badRequest()->status);
        self::assertSame(501, HttpException::notImplemented()->status);
        self::assertSame('Client Error', HttpException::titleOf(418));
        self::assertSame('Server Error', HttpException::titleOf(599));

        $custom = new HttpException(
            402,
            'Top up the account first.',
            type: 'https://example.test/problems/payment-required',
            title: 'Payment required',
            extensions: ['price' => '9.99'],
        );

        self::assertSame(
            [
                'type' => 'https://example.test/problems/payment-required',
                'title' => 'Payment required',
                'status' => 402,
                'detail' => 'Top up the account first.',
                'price' => '9.99',
            ],
            $custom->problem()->toArray(),
        );
    }

    public function testAnHttpExceptionBecomesAProblemResponse(): void
    {
        $logger = new RecordingLogger();
        $response = self::pipeline(HttpException::methodNotAllowed(['GET']), $logger)->handle(self::request('PUT', '/accounts'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('GET', $response->getHeaderLine('Allow'));
        self::assertSame(['type' => 'about:blank', 'title' => 'Method Not Allowed', 'status' => 405], self::json($response));
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame(405, $logger->records[0]['context']['status']);
        self::assertSame('PUT', $logger->records[0]['context']['method']);
        self::assertSame('/accounts', $logger->records[0]['context']['path']);
    }

    public function testAContractViolationListsItsErrors(): void
    {
        $violation = new ContractViolation([
            new Violation('query', 'limit', '', 'Too high.'),
            new Violation('body', '', '/owner', 'Too short.'),
        ]);

        self::assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Unprocessable Content',
                'status' => 422,
                'detail' => 'The request does not match the contract: 2 errors.',
                'errors' => [
                    ['in' => 'query', 'name' => 'limit', 'detail' => 'Too high.'],
                    ['in' => 'body', 'pointer' => '/owner', 'detail' => 'Too short.'],
                ],
            ],
            self::json(self::pipeline($violation)->handle(self::request('GET', '/accounts'))),
        );
    }

    public function testAnUnexpectedExceptionRevealsNothing(): void
    {
        $logger = new RecordingLogger();
        $failure = new RuntimeException('SQLSTATE[08006] password authentication failed for user "acta"');
        $response = self::pipeline($failure, $logger)->handle(self::request('GET', '/accounts'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['type' => 'about:blank', 'title' => 'Internal Server Error', 'status' => 500], self::json($response));
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame($failure, $logger->records[0]['context']['exception']);
        self::assertStringContainsString('SQLSTATE', (string) $logger->records[0]['context']['reason']);
    }

    public function testDebugAddsTheExceptionToTheDocument(): void
    {
        $failure = new RuntimeException('Outer', previous: new LogicException('Inner'));
        $problem = self::json(self::pipeline($failure, debug: true)->handle(self::request('GET', '/accounts')));

        self::assertIsArray($problem['exception']);
        self::assertSame(RuntimeException::class, $problem['exception']['class']);
        self::assertSame('Outer', $problem['exception']['message']);
        self::assertStringStartsWith(__FILE__ . ':', $problem['exception']['file']);
        self::assertNotEmpty($problem['exception']['trace']);
        self::assertSame('Inner', $problem['exception']['previous']['message']);

        // An HTTP exception is already meant for the client: nothing is added.
        self::assertArrayNotHasKey(
            'exception',
            self::json(self::pipeline(HttpException::notFound(), debug: true)->handle(self::request('GET', '/accounts'))),
        );
    }

    public function testMappersGiveAStatusToOtherExceptions(): void
    {
        $domain = new class implements ExceptionMapper {
            #[Override]
            public function map(Throwable $exception): ?HttpException
            {
                return $exception instanceof DomainException
                    ? new HttpException(422, $exception->getMessage(), type: 'https://example.test/problems/rule', title: 'Business rule', previous: $exception)
                    : null;
            }
        };
        $never = new class implements ExceptionMapper {
            #[Override]
            public function map(Throwable $exception): null
            {
                return null;
            }
        };
        $logger = new RecordingLogger();
        $failure = new DomainException('A deposit must be positive.');

        $response = self::pipeline($failure, $logger, false, $never, $domain)->handle(self::request('POST', '/accounts'));

        self::assertSame(
            ['type' => 'https://example.test/problems/rule', 'title' => 'Business rule', 'status' => 422, 'detail' => 'A deposit must be positive.'],
            self::json($response),
        );
        // The original exception is what gets logged.
        self::assertSame($failure, $logger->records[0]['context']['exception']);
        self::assertSame(500, self::pipeline(new LogicException('Unknown'), $logger, false, $never, $domain)->handle(self::request('GET', '/'))->getStatusCode());
    }

    public function testTheTraceIsQuotedInTheDocumentAndTheLog(): void
    {
        $logger = new RecordingLogger();
        $pipeline = new Pipeline(
            self::throwing(HttpException::notFound()),
            new CorrelationMiddleware(),
            new ProblemDetailsMiddleware(self::responder(), logger: $logger),
        );

        $response = $pipeline->handle(self::request('GET', '/nope', ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01']));

        self::assertSame('0af7651916cd43dd8448eb211c80319c', self::json($response)['trace_id']);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $logger->records[0]['context']['trace_id']);
        self::assertStringStartsWith('00-0af7651916cd43dd8448eb211c80319c-', $response->getHeaderLine('traceparent'));
    }

    public function testASuccessfulResponseIsLeftAlone(): void
    {
        $response = self::responder()->empty();
        $handler = new readonly class ($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response) {}

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        self::assertSame($response, new Pipeline($handler, new ProblemDetailsMiddleware(self::responder()))->handle(self::request('GET', '/')));
    }

    private static function pipeline(Throwable $failure, ?RecordingLogger $logger = null, bool $debug = false, ExceptionMapper ...$mappers): Pipeline
    {
        return new Pipeline(
            self::throwing($failure),
            new ProblemDetailsMiddleware(self::responder(), array_values($mappers), $logger ?? new RecordingLogger(), $debug),
        );
    }

    private static function throwing(Throwable $failure): RequestHandlerInterface
    {
        return new readonly class ($failure) implements RequestHandlerInterface {
            public function __construct(private Throwable $failure) {}

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->failure;
            }
        };
    }
}
