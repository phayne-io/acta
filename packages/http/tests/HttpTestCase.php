<?php

declare(strict_types=1);

namespace Acta\Http\Tests;

use Acta\Http\OpenApi\Contract;
use Acta\Http\OpenApi\ContractLoader;
use Acta\Http\Response\Responder;
use Acta\Http\Routing\RouteMatch;
use Acta\Http\Routing\Router;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Helpers shared by the tests: the banking contract and PSR-7 messages.
 */
abstract class HttpTestCase extends TestCase
{
    protected const string ACCOUNT_ID = '018f4e3a-7b2c-7d1e-9a0b-1c2d3e4f5a6b';

    private static ?Contract $contract = null;

    public static function contract(): Contract
    {
        return self::$contract ??= ContractLoader::load(__DIR__ . '/Fixture/openapi.yaml');
    }

    /**
     * Builds a contract with one operation, to test a single feature of the description.
     *
     * @param array<string, mixed> $operation  the Operation Object, without its `operationId` and `responses`
     * @param array<string, mixed> $components
     */
    public static function contractWith(array $operation, string $path = '/things', string $method = 'get', array $components = []): Contract
    {
        return Contract::fromDocument([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test', 'version' => '1'],
            'paths' => [$path => [$method => ['operationId' => 'test', 'responses' => ['200' => ['description' => 'OK']], ...$operation]]],
            ...($components === [] ? [] : ['components' => $components]),
        ]);
    }

    /**
     * @param array<non-empty-string, string> $headers
     * @param string|array<mixed>|null        $body    arrays are sent as JSON
     * @param array<string, string>           $cookies
     */
    public static function request(
        string $method,
        string $uri,
        array $headers = [],
        string|array|null $body = null,
        array $cookies = [],
    ): ServerRequestInterface {
        if (is_array($body)) {
            $body = (string) json_encode($body);
            $headers = ['Content-Type' => 'application/json', ...$headers];
        }

        $request = new ServerRequest([], [], $uri, $method, new StreamFactory()->createStream($body ?? ''), $headers, $cookies);
        parse_str($request->getUri()->getQuery(), $query);

        return $request->withQueryParams($query);
    }

    public static function routed(ServerRequestInterface $request, ?Contract $contract = null): RouteMatch
    {
        return new Router($contract ?? self::contract())->match($request->getMethod(), $request->getUri()->getPath());
    }

    public static function responder(): Responder
    {
        return new Responder(new ResponseFactory(), new StreamFactory());
    }

    /**
     * @return array<string, mixed>
     */
    public static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $map = [];

        foreach ($decoded as $key => $value) {
            $map[(string) $key] = $value;
        }

        return $map;
    }
}
