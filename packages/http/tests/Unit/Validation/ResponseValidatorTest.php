<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Unit\Validation;

use Acta\Http\Exception\ResponseContractViolation;
use Acta\Http\Pipeline;
use Acta\Http\Routing\Router;
use Acta\Http\Routing\RoutingMiddleware;
use Acta\Http\Testing\ContractAssertions;
use Acta\Http\Tests\Fixture\RecordingLogger;
use Acta\Http\Tests\HttpTestCase;
use Acta\Http\Validation\ResponseValidationMiddleware;
use Acta\Http\Validation\ResponseValidationMode;
use Acta\Http\Validation\ResponseValidator;
use Acta\Http\Validation\Violation;
use Override;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ResponseValidatorTest extends HttpTestCase
{
    private const array ACCOUNT = [
        'id' => self::ACCOUNT_ID,
        'owner' => 'Ada',
        'balance' => ['amount' => '12000', 'currency' => 'EUR'],
        'version' => 2,
    ];

    public function testADeclaredResponseIsValid(): void
    {
        $response = self::responder()->json(self::ACCOUNT, 200, ['ETag' => '"2"', 'X-Rate-Limit' => '99']);

        self::assertSame([], self::violations('getAccount', $response));
        // The body can still be read by whoever sends the response.
        self::assertSame(self::ACCOUNT, self::json($response));

        ContractAssertions::assertResponseMatchesContract(self::contract(), 'getAccount', $response);
    }

    public function testAResponseWithoutBodyIsValidWhenNoneIsDeclared(): void
    {
        self::assertSame([], self::violations('closeAccount', self::responder()->empty()));
    }

    public function testANonJsonBodyIsValidatedAsAString(): void
    {
        $csv = static fn(string $body): ResponseInterface => self::responder()->empty(200)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withBody(new \Laminas\Diactoros\StreamFactory()->createStream($body));

        self::assertSame([], self::violations('getStatement', $csv("date;amount\n")));
    }

    public function testAnUndeclaredStatusIsReported(): void
    {
        self::assertSame(
            ['[status] Status 500 is not declared; the operation declares 200, 4XX.'],
            self::violations('listAccounts', self::responder()->json([], 500)),
        );
        self::assertSame(
            ['[status] Status 200 is not declared; the operation declares 204.'],
            self::violations('closeAccount', self::responder()->json([])),
        );
    }

    public function testHeadersAreChecked(): void
    {
        self::assertSame(
            ['[header ETag] This header is required.'],
            self::violations('getAccount', self::responder()->json(self::ACCOUNT)),
        );
        self::assertSame(
            [
                '[header ETag] The string should match pattern: ^"\d+"$',
                '[header X-Rate-Limit] The data (string) must match the type: integer',
            ],
            self::violations('getAccount', self::responder()->json(self::ACCOUNT, 200, ['ETag' => 'W/2', 'X-Rate-Limit' => 'lots'])),
        );
    }

    public function testTheBodyIsCheckedAgainstItsSchema(): void
    {
        $account = [...self::ACCOUNT, 'balance' => ['amount' => 12000, 'currency' => 'EUR'], 'version' => 0];

        self::assertSame(
            [
                '[body /balance/amount] The data (integer) must match the type: string',
                '[body /version] Number must be greater than or equal to 1',
            ],
            self::violations('getAccount', self::responder()->json($account, 200, ['ETag' => '"2"'])),
        );
    }

    public function testTheMediaTypeAndThePresenceOfTheBodyAreChecked(): void
    {
        $etag = ['ETag' => '"2"'];
        $empty = self::responder()->empty(200, $etag);
        $text = $empty->withHeader('Content-Type', 'text/plain')->withBody(new \Laminas\Diactoros\StreamFactory()->createStream('hello'));
        $broken = $empty->withHeader('Content-Type', 'application/json')->withBody(new \Laminas\Diactoros\StreamFactory()->createStream('{'));

        self::assertSame(['[body] A body is declared for status 200.'], self::violations('getAccount', $empty));
        self::assertSame(
            ['[header Content-Type] "text/plain" is not declared for status 200; expected application/json.'],
            self::violations('getAccount', $text),
        );
        self::assertSame(['[body] The body is not valid JSON: Syntax error.'], self::violations('getAccount', $broken));
        self::assertSame(
            ['[body] No body is declared for status 204.'],
            self::violations('closeAccount', self::responder()->json(['closed' => true], 204)),
        );
    }

    public function testAProblemDocumentMatchesTheDefaultResponse(): void
    {
        $problem = self::responder()->problem(\Acta\Http\Exception\HttpException::conflict('Already open.')->problem());

        self::assertSame([], self::violations('openAccount', $problem));
        self::assertSame([], self::violations('listAccounts', $problem));
    }

    public function testTheAssertionDescribesWhatDiffers(): void
    {
        try {
            ContractAssertions::assertResponseMatchesContract(self::contract(), 'closeAccount', self::responder()->json([]));
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('declares for "closeAccount"', $failure->getMessage());

            return;
        }

        self::fail('The assertion should fail.');
    }

    public function testTheMiddlewareFailsOnAnUndeclaredResponse(): void
    {
        $pipeline = $this->pipeline(self::responder()->json(['oops' => true]), ResponseValidationMode::Fail);

        try {
            $pipeline->handle(self::request('DELETE', '/accounts/' . self::ACCOUNT_ID));
            self::fail('The response should be rejected.');
        } catch (ResponseContractViolation $violation) {
            self::assertSame('closeAccount', $violation->operationId);
            self::assertCount(1, $violation->violations);
            self::assertSame(
                'The response of operation "closeAccount" does not match the contract: '
                . '[status] Status 200 is not declared; the operation declares 204.',
                $violation->getMessage(),
            );
        }
    }

    public function testTheMiddlewareCanLogInsteadOfFailing(): void
    {
        $logger = new RecordingLogger();
        $response = self::responder()->json(['oops' => true]);

        self::assertSame(
            $response,
            $this->pipeline($response, ResponseValidationMode::Log, $logger)->handle(self::request('DELETE', '/accounts/' . self::ACCOUNT_ID)),
        );
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('closeAccount', $logger->records[0]['context']['operation']);
        self::assertSame(200, $logger->records[0]['context']['status']);
        self::assertInstanceOf(ResponseContractViolation::class, $logger->records[0]['context']['exception']);
    }

    public function testTheMiddlewareLetsDeclaredResponsesThrough(): void
    {
        $logger = new RecordingLogger();
        $response = self::responder()->empty();

        self::assertSame(
            $response,
            $this->pipeline($response, ResponseValidationMode::Fail, $logger)->handle(self::request('DELETE', '/accounts/' . self::ACCOUNT_ID)),
        );
        self::assertSame([], $logger->records);
    }

    public function testTheMiddlewareIgnoresRequestsThatWereNotRouted(): void
    {
        $response = self::responder()->json(['anything' => true]);
        $pipeline = new Pipeline(
            self::answering($response),
            new ResponseValidationMiddleware(new ResponseValidator(self::contract())),
        );

        self::assertSame($response, $pipeline->handle(self::request('GET', '/health')));
    }

    private function pipeline(ResponseInterface $response, ResponseValidationMode $mode, ?RecordingLogger $logger = null): Pipeline
    {
        return new Pipeline(
            self::answering($response),
            new RoutingMiddleware(new Router(self::contract())),
            new ResponseValidationMiddleware(new ResponseValidator(self::contract()), $mode, $logger ?? new RecordingLogger()),
        );
    }

    private static function answering(ResponseInterface $response): RequestHandlerInterface
    {
        return new readonly class ($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response) {}

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    /**
     * @return list<string>
     */
    private static function violations(string $operationId, ResponseInterface $response): array
    {
        return array_map(
            static fn(Violation $violation): string => $violation->describe(),
            new ResponseValidator(self::contract())->validate($response, self::contract()->operation($operationId)),
        );
    }
}
