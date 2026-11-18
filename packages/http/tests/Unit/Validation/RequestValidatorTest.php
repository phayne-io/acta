<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Unit\Validation;

use Acta\Http\Exception\ContractError;
use Acta\Http\Exception\ContractViolation;
use Acta\Http\Exception\HttpException;
use Acta\Http\Handler\OperationDispatcher;
use Acta\Http\Handler\OperationHandlers;
use Acta\Http\OpenApi\Contract;
use Acta\Http\Routing\Router;
use Acta\Http\Routing\RoutingMiddleware;
use Acta\Http\Testing\Pipeline;
use Acta\Http\Tests\HttpTestCase;
use Acta\Http\Validation\Input;
use Acta\Http\Validation\RequestValidationMiddleware;
use Acta\Http\Validation\RequestValidator;
use Acta\Http\Validation\Violation;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;

final class RequestValidatorTest extends HttpTestCase
{
    public function testQueryParametersAreDecodedWithTheirStyleAndTyped(): void
    {
        $id = self::ACCOUNT_ID;
        $input = self::validate(self::request('GET', '/accounts'
            . '?limit=50'
            . '&currency=EUR&currency=USD'
            . "&ids={$id},{$id}"
            . '&tags=a%7Cb%20c|d'
            . '&filter[owner]=Ada%20L&filter[minBalance]=100&filter[active]=true'
            . '&from=1&to=9'
            . '&ratio=0.5'
            . '&cursor=%7B%22after%22%3A12%7D'
            . '&unknown=ignored'));

        self::assertSame(
            [
                'limit' => 50,
                'currency' => ['EUR', 'USD'],
                'ids' => [$id, $id],
                'tags' => ['a', 'b c', 'd'],
                'filter' => ['owner' => 'Ada L', 'minBalance' => 100, 'active' => true],
                'range' => ['from' => 1, 'to' => 9],
                'ratio' => 0.5,
                'cursor' => ['after' => 12],
            ],
            $input->query,
        );
        self::assertSame([], $input->path);
        self::assertNull($input->body);
        self::assertNull($input->contentType);
    }

    public function testAbsentParametersTakeTheDefaultOfTheirSchema(): void
    {
        self::assertSame(['limit' => 20], self::validate(self::request('GET', '/accounts'))->query);
    }

    public function testArraysAlsoAcceptTheBracketNotation(): void
    {
        $input = self::validate(self::request('GET', '/accounts?currency[]=EUR&currency%5B%5D=USD&ratio=3'));

        self::assertSame(['EUR', 'USD'], $input->query['currency']);
        self::assertSame(3, $input->query['ratio']);
    }

    public function testPathHeaderAndCookieParametersAreDecoded(): void
    {
        $input = self::validate(self::request(
            'GET',
            '/accounts/' . self::ACCOUNT_ID,
            ['X-Flags' => '1, 2,3', 'Accept' => 'application/json'],
            cookies: ['session' => 'abc123'],
        ));

        self::assertSame(['accountId' => self::ACCOUNT_ID], $input->path);
        // Accept is declared but, as OpenAPI asks, not treated as a parameter.
        self::assertSame(['X-Flags' => [1, 2, 3]], $input->headers);
        self::assertSame(['session' => 'abc123'], $input->cookies);
    }

    /**
     * @param list<array<string, string>> $expected
     */
    #[DataProvider('invalidRequests')]
    public function testViolationsAreCollected(ServerRequestInterface $request, array $expected): void
    {
        try {
            self::validate($request);
            self::fail('The request should be rejected.');
        } catch (ContractViolation $violation) {
            self::assertSame(422, $violation->status);
            self::assertSame($expected, $violation->extensions['errors']);
            self::assertSame($expected, array_map(static fn(Violation $v): array => $v->toArray(), $violation->violations));
        }
    }

    /**
     * @return iterable<string, array{ServerRequestInterface, list<array<string, string>>}>
     */
    public static function invalidRequests(): iterable
    {
        $account = '/accounts/' . self::ACCOUNT_ID;

        yield 'query: wrong type, out of range, bad enum' => [
            self::request('GET', '/accounts?limit=many&currency=EUR&currency=GBP&ratio=1e'),
            [
                ['in' => 'query', 'name' => 'limit', 'detail' => 'The data (string) must match the type: integer'],
                ['in' => 'query', 'name' => 'currency', 'pointer' => '/1', 'detail' => 'The data should match one item from enum'],
                ['in' => 'query', 'name' => 'ratio', 'detail' => 'The data (string) must match the type: number'],
            ],
        ];
        yield 'query: above the maximum' => [
            self::request('GET', '/accounts?limit=101'),
            [['in' => 'query', 'name' => 'limit', 'detail' => 'Number must be lower than or equal to 100']],
        ];
        yield 'query: deep object with an unknown property' => [
            self::request('GET', '/accounts?filter[owner]=Ada&filter[nope]=1'),
            [['in' => 'query', 'name' => 'filter', 'detail' => 'Additional object properties are not allowed: nope']],
        ];
        yield 'query: content parameter that is not JSON' => [
            self::request('GET', '/accounts?cursor=nope'),
            [['in' => 'query', 'name' => 'cursor', 'detail' => 'The data (string) must match the type: object']],
        ];
        yield 'query: required parameter' => [
            self::request('GET', '/accounts/search'),
            [['in' => 'query', 'name' => 'q', 'detail' => 'This parameter is required.']],
        ];
        yield 'path, header and cookie' => [
            self::request('GET', '/accounts/not-a-uuid', ['X-Flags' => '1,2,3,x'], cookies: ['session' => 'XYZ']),
            [
                ['in' => 'path', 'name' => 'accountId', 'detail' => "The data must match the 'uuid' format"],
                ['in' => 'header', 'name' => 'X-Flags', 'detail' => 'Array should have at most 3 items, 4 found'],
                ['in' => 'cookie', 'name' => 'session', 'detail' => 'The string should match pattern: ^[a-f0-9]+$'],
            ],
        ];
        yield 'required header' => [
            self::request('GET', $account),
            [['in' => 'header', 'name' => 'X-Flags', 'detail' => 'This parameter is required.']],
        ];
        yield 'body: missing and nested errors, with a parameter error' => [
            self::request('POST', '/accounts/nope/deposits', body: ['money' => ['amount' => '1.5', 'currency' => 'GBP']]),
            [
                ['in' => 'path', 'name' => 'accountId', 'detail' => "The data must match the 'uuid' format"],
                ['in' => 'body', 'pointer' => '/money/amount', 'detail' => 'The string should match pattern: ^-?\d+$'],
                ['in' => 'body', 'pointer' => '/money/currency', 'detail' => 'The data should match one item from enum'],
            ],
        ];
        yield 'body: required property' => [
            self::request('POST', '/accounts', body: ['currency' => 'EUR']),
            [['in' => 'body', 'detail' => 'The required properties (owner) are missing']],
        ];
        yield 'body: required' => [
            self::request('POST', '/accounts'),
            [['in' => 'body', 'detail' => 'A request body is required.']],
        ];
        yield 'body: form field of the wrong type' => [
            self::request('POST', $account . '/deposits', ['Content-Type' => 'application/x-www-form-urlencoded'], 'amount=ten'),
            [['in' => 'body', 'pointer' => '/amount', 'detail' => 'The data (string) must match the type: integer']],
        ];
    }

    public function testAJsonBodyIsDecodedToArrays(): void
    {
        $input = self::validate(self::request(
            'POST',
            '/accounts',
            ['Content-Type' => 'application/json; charset=utf-8', 'Idempotency-Key' => 'key-12345'],
            '{"owner":"Ada","currency":"USD"}',
        ));

        self::assertSame(['owner' => 'Ada', 'currency' => 'USD'], $input->body);
        self::assertSame(['owner' => 'Ada', 'currency' => 'USD'], $input->fields());
        self::assertSame('application/json', $input->contentType);
        self::assertSame(['Idempotency-Key' => 'key-12345'], $input->headers);
    }

    public function testAFormBodyIsDecodedAndTyped(): void
    {
        $input = self::validate(self::request(
            'POST',
            '/accounts/' . self::ACCOUNT_ID . '/deposits',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'amount=120&urgent=true&note=a+b%21',
        ));

        self::assertSame(['amount' => 120, 'urgent' => true, 'note' => 'a b!'], $input->body);
        self::assertSame('application/x-www-form-urlencoded', $input->contentType);
    }

    public function testAMultipartBodyIsReadFromTheParsedBody(): void
    {
        $contract = self::contractWith([
            'requestBody' => ['content' => ['multipart/form-data' => ['schema' => [
                'type' => 'object',
                'properties' => ['pages' => ['type' => 'integer']],
            ]]]],
        ], method: 'post');
        $request = self::request('POST', '/things', ['Content-Type' => 'multipart/form-data; boundary=x'], '--x--')
            ->withParsedBody(['pages' => '3', 'title' => 'Report']);

        self::assertSame(['pages' => 3, 'title' => 'Report'], self::validate($request, $contract)->body);
    }

    public function testAnyOtherBodyIsKeptAsAString(): void
    {
        $input = self::validate(self::request(
            'POST',
            '/accounts/' . self::ACCOUNT_ID . '/deposits',
            ['Content-Type' => 'text/plain'],
            '120 EUR',
        ));

        self::assertSame('120 EUR', $input->body);
        self::assertSame([], $input->fields());
        self::assertSame('text/plain', $input->contentType);
    }

    public function testAnOptionalBodyMayBeAbsent(): void
    {
        $input = self::validate(self::request('GET', '/accounts/' . self::ACCOUNT_ID . '/statement?from=2026-01-31'));

        self::assertNull($input->body);
        self::assertSame(['from' => '2026-01-31'], $input->query);
    }

    public function testABodyIsIgnoredWhenTheOperationDeclaresNone(): void
    {
        $input = self::validate(self::request('DELETE', '/accounts/' . self::ACCOUNT_ID, body: ['anything' => true]));

        self::assertNull($input->body);
    }

    public function testAnUndeclaredMediaTypeIsUnsupported(): void
    {
        try {
            self::validate(self::request('POST', '/accounts', ['Content-Type' => 'application/xml'], '<account/>'));
            self::fail('The media type should be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(415, $exception->status);
            self::assertSame('This operation accepts application/json.', $exception->getMessage());
        }
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        try {
            self::validate(self::request('POST', '/accounts', ['Content-Type' => 'application/json'], '{"owner": '));
            self::fail('The body should be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(400, $exception->status);
            self::assertStringStartsWith('The body is not valid JSON', $exception->getMessage());
        }
    }

    public function testTheStatusOfViolationsIsConfigurable(): void
    {
        $validator = new RequestValidator(self::contract(), violationStatus: 400);
        $request = self::request('GET', '/accounts?limit=0');

        try {
            $validator->validate($request, self::routed($request));
            self::fail('The request should be rejected.');
        } catch (ContractViolation $violation) {
            self::assertSame(400, $violation->status);
            self::assertSame('The request does not match the contract: 1 error.', $violation->getMessage());
        }
    }

    public function testStringsAreOnlyConvertedWhenTheSchemaAsksForIt(): void
    {
        $parameter = static fn(string $name, array $schema): array => ['name' => $name, 'in' => 'query', 'schema' => $schema];
        $contract = self::contractWith(
            ['parameters' => [
                $parameter('text', ['type' => 'string']),
                $parameter('either', ['type' => ['string', 'integer']]),
                $parameter('free', ['description' => 'Anything']),
                $parameter('nullable', ['type' => ['integer', 'null']]),
                $parameter('flag', ['type' => 'boolean']),
                $parameter('big', ['type' => 'number']),
                $parameter('ref', ['$ref' => '#/components/schemas/Count']),
                $parameter('composed', ['allOf' => [['$ref' => '#/components/schemas/Count'], ['maximum' => 10]]]),
                $parameter('choice', ['oneOf' => [['type' => 'boolean'], ['type' => 'integer']]]),
                $parameter('points', ['type' => 'array', 'items' => ['type' => 'integer']]),
                $parameter('untyped', ['type' => 'array']),
                $parameter('map', ['type' => 'object', 'additionalProperties' => ['type' => 'integer']]) + ['style' => 'deepObject'],
            ]],
            components: ['schemas' => ['Count' => ['type' => 'integer']]],
        );

        $input = self::validate(self::request('GET', '/things'
            . '?text=42&either=42&free=42&nullable=&flag=false&big=1e3&ref=7&composed=8&choice=true'
            . '&points=1&points=2&untyped=1&map[a]=1&map[b]=2'), $contract);

        self::assertSame(
            [
                'text' => '42',
                'either' => '42',
                'free' => '42',
                'nullable' => null,
                'flag' => false,
                'big' => 1000.0,
                'ref' => 7,
                'composed' => 8,
                'choice' => true,
                'points' => [1, 2],
                'untyped' => ['1'],
                'map' => ['a' => 1, 'b' => 2],
            ],
            $input->query,
        );
    }

    public function testObjectsAndArraysFollowTheSimpleAndDelimitedStyles(): void
    {
        $contract = self::contractWith(
            ['parameters' => [
                ['name' => 'point', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer'], 'y' => ['type' => 'integer']]]],
                ['name' => 'X-Point', 'in' => 'header', 'explode' => true, 'schema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]]],
                ['name' => 'words', 'in' => 'query', 'style' => 'spaceDelimited', 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ['name' => 'size', 'in' => 'query', 'explode' => false, 'schema' => ['type' => 'object', 'properties' => ['w' => ['type' => 'integer']]]],
                ['name' => 'empty', 'in' => 'query', 'explode' => false, 'schema' => ['type' => 'array']],
                ['name' => 'ids', 'in' => 'cookie', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'integer']]],
            ]],
            '/points/{point}',
        );

        $input = self::validate(
            self::request('GET', '/points/x,1,y,2?words=a%20b&size=w,3,h,4&empty=', ['X-Point' => 'x=5, label=home'], cookies: ['ids' => '1,2']),
            $contract,
        );

        self::assertSame(['point' => ['x' => 1, 'y' => 2]], $input->path);
        self::assertSame(['X-Point' => ['x' => 5, 'label' => 'home']], $input->headers);
        self::assertSame(['words' => ['a', 'b'], 'size' => ['w' => 3, 'h' => '4'], 'empty' => []], $input->query);
        self::assertSame(['ids' => [1, 2]], $input->cookies);
    }

    public function testAnInvalidSchemaIsADefectOfTheContract(): void
    {
        $contract = self::contractWith(['parameters' => [['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integr']]]]);

        $this->expectException(ContractError::class);
        $this->expectExceptionMessage('Invalid schema');

        self::validate(self::request('GET', '/things?page=1'), $contract);
    }

    public function testTheMiddlewareGivesTheInputToTheHandler(): void
    {
        $seen = [];
        $pipeline = new Pipeline(
            new OperationDispatcher(new OperationHandlers()->on(
                'openAccount',
                static function (ServerRequestInterface $request) use (&$seen) {
                    $seen = [Input::from($request)->body, $request->getParsedBody()];

                    return HttpTestCase::responder()->empty();
                },
            )),
            new RoutingMiddleware(new Router(self::contract())),
            new RequestValidationMiddleware(new RequestValidator(self::contract())),
        );

        $pipeline->handle(self::request('POST', '/accounts', body: ['owner' => 'Ada']));

        self::assertSame([['owner' => 'Ada'], ['owner' => 'Ada']], $seen);
    }

    public function testAnInputIsOnlyAvailableAfterValidation(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('add RequestValidationMiddleware');

        Input::from(self::request('GET', '/accounts'));
    }

    private static function validate(ServerRequestInterface $request, ?Contract $contract = null): Input
    {
        return new RequestValidator($contract ?? self::contract())->validate($request, self::routed($request, $contract));
    }
}
