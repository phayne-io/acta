<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Unit\OpenApi;

use Acta\Http\Exception\ContractError;
use Acta\Http\Exception\UnknownOperation;
use Acta\Http\OpenApi\Contract;
use Acta\Http\OpenApi\ParameterLocation;
use Acta\Http\Tests\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ContractTest extends HttpTestCase
{
    public function testItReadsTheOperationsOfTheDescription(): void
    {
        $contract = self::contract();

        self::assertSame('3.1.0', $contract->openapi);
        self::assertSame('Banking API', $contract->title);
        self::assertSame('1.0.0', $contract->version);
        self::assertSame(
            ['listAccounts', 'openAccount', 'searchAccounts', 'getAccount', 'closeAccount', 'depositMoney', 'getStatement'],
            array_keys($contract->operations),
        );
        self::assertTrue($contract->hasOperation('openAccount'));
        self::assertFalse($contract->hasOperation('notifyAccountOpened'));

        $operation = $contract->operation('openAccount');

        self::assertSame('POST', $operation->method);
        self::assertSame('/accounts', $operation->path);
        self::assertSame('/paths/~1accounts/post', $operation->pointer);
        self::assertSame('Opens an account', $operation->summary);
        self::assertSame(['accounts'], $operation->tags);
        self::assertSame(['x-command' => 'OpenAccount'], $operation->extensions);
        self::assertFalse($operation->deprecated);
        self::assertFalse($operation->isWebhook);
    }

    public function testAnOperationMergesAndOverridesTheParametersOfItsPath(): void
    {
        $get = self::contract()->operation('getAccount');
        $delete = self::contract()->operation('closeAccount');

        // The path declares accountId and an optional X-Flags; GET redeclares X-Flags as required.
        self::assertSame(['accountId', 'X-Flags', 'session', 'Accept'], array_column($get->parameters, 'name'));
        self::assertTrue($get->parametersIn(ParameterLocation::Header)[0]->required);
        self::assertFalse($delete->parametersIn(ParameterLocation::Header)[0]->required);

        $accountId = $get->parametersIn(ParameterLocation::Path)[0];

        // A referenced parameter is read where it is defined.
        self::assertSame('/components/parameters/AccountId', $accountId->pointer);
        self::assertSame('/components/parameters/AccountId/schema', $accountId->schemaPointer);
        self::assertSame('simple', $accountId->style);
        self::assertFalse($accountId->explode);
    }

    public function testParametersGetTheDefaultStyleOfTheirLocation(): void
    {
        $parameters = array_column(self::contract()->operation('listAccounts')->parameters, null, 'name');

        self::assertSame(['form', true], [$parameters['currency']->style, $parameters['currency']->explode]);
        self::assertSame(['form', false], [$parameters['ids']->style, $parameters['ids']->explode]);
        self::assertSame(['pipeDelimited', false], [$parameters['tags']->style, $parameters['tags']->explode]);
        self::assertSame(['deepObject', false], [$parameters['filter']->style, $parameters['filter']->explode]);
        self::assertSame('application/json', $parameters['cursor']->mediaType);
        self::assertSame(
            '/paths/~1accounts/get/parameters/7/content/application~1json/schema',
            $parameters['cursor']->schemaPointer,
        );
    }

    public function testResponsesAreFoundByCodeThenRangeThenDefault(): void
    {
        $open = self::contract()->operation('openAccount');
        $list = self::contract()->operation('listAccounts');

        self::assertSame('201', $open->responseFor(201)?->status);
        self::assertSame('default', $open->responseFor(409)?->status);
        self::assertSame('4XX', $list->responseFor(404)?->status);
        self::assertNull($list->responseFor(500));

        $created = $open->responseFor(201);

        self::assertNotNull($created);
        self::assertSame(['location', 'etag'], array_keys($created->headers));
        self::assertTrue($created->headers['location']->required);
        self::assertSame('/paths/~1accounts/post/responses/201/content/application~1json/schema', $created->content['application/json']->schemaPointer);
        // A referenced response is read where it is defined.
        self::assertSame(
            '/components/responses/Problem/content/application~1problem+json/schema',
            $open->responseFor(500)?->content['application/problem+json']->schemaPointer,
        );
    }

    public function testSecurityDefaultsToTheRequirementsOfTheDocument(): void
    {
        $contract = self::contract();

        self::assertSame([['bearer' => []]], $contract->operation('getAccount')->security);
        self::assertSame([['bearer' => ['accounts:write']]], $contract->operation('openAccount')->security);
        self::assertSame([['bearer' => ['accounts:read']], ['apiKey' => []]], $contract->operation('listAccounts')->security);
        self::assertSame([], $contract->operation('searchAccounts')->security);

        self::assertSame(['bearer', 'apiKey'], array_keys($contract->securitySchemes));
        self::assertSame('http', $contract->securitySchemes['bearer']->type);
        self::assertSame('bearer', $contract->securitySchemes['bearer']->scheme);
        self::assertSame('X-Api-Key', $contract->securitySchemes['apiKey']->definition['name']);
    }

    public function testWebhooksAreKeptApartFromThePaths(): void
    {
        $webhook = self::contract()->webhook('notifyAccountOpened');

        self::assertTrue($webhook->isWebhook);
        self::assertSame('accountOpened', $webhook->path);
        self::assertSame('POST', $webhook->method);
        self::assertTrue($webhook->requestBody?->required);
        self::assertSame('2XX', $webhook->responseFor(204)?->status);
    }

    public function testAnUnknownOperationIsAnError(): void
    {
        $this->expectException(UnknownOperation::class);
        $this->expectExceptionMessage('"nope"');

        self::contract()->operation('nope');
    }

    public function testAnUnknownWebhookIsAnError(): void
    {
        $this->expectException(UnknownOperation::class);

        self::contract()->webhook('openAccount');
    }

    /**
     * @param array<string, mixed> $document
     */
    #[DataProvider('invalidDocuments')]
    public function testAnUnusableDescriptionIsRejected(array $document, string $message, string $pointer): void
    {
        try {
            Contract::fromDocument($document);
            self::fail('The description should be rejected.');
        } catch (ContractError $error) {
            self::assertStringContainsString($message, $error->getMessage());
            self::assertSame($pointer, $error->pointer);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidDocuments(): iterable
    {
        $with = static fn(array $paths, array $more = []): array => ['openapi' => '3.1.0', 'paths' => $paths, ...$more];
        $ok = ['200' => ['description' => 'OK']];

        yield 'OpenAPI 3.0' => [['openapi' => '3.0.3'], 'Only OpenAPI 3.1', '/openapi'];
        yield 'no version' => [['paths' => []], 'no "openapi" version', '/openapi'];
        yield 'relative path' => [$with(['things' => []]), 'must start with a slash', '/paths/things'];
        yield 'no operationId' => [$with(['/things' => ['get' => ['responses' => $ok]]]), 'needs an "operationId"', '/paths/~1things/get'];
        yield 'duplicate operationId' => [
            $with(['/a' => ['get' => ['operationId' => 'x']], '/b' => ['get' => ['operationId' => 'x']]]),
            'already used at #/paths/~1a/get',
            '/paths/~1b/get',
        ];
        yield 'operationId shared with a webhook' => [
            $with(['/a' => ['get' => ['operationId' => 'x']]], ['webhooks' => ['w' => ['post' => ['operationId' => 'x']]]]),
            'already used',
            '/webhooks/w/post',
        ];
        yield 'undeclared path variable' => [
            $with(['/things/{id}' => ['get' => ['operationId' => 'x']]]),
            'declare it as a required path parameter',
            '/paths/~1things~1{id}/get',
        ];
        yield 'optional path variable' => [
            $with(['/things/{id}' => ['get' => ['operationId' => 'x', 'parameters' => [['name' => 'id', 'in' => 'path']]]]]),
            'declare it as a required path parameter',
            '/paths/~1things~1{id}/get',
        ];
        yield 'path parameter without variable' => [
            $with(['/things' => ['get' => ['operationId' => 'x', 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true]]]]]),
            'does not appear in "/things"',
            '/paths/~1things/get/parameters/0',
        ];
        yield 'parameter without location' => [
            $with(['/things' => ['get' => ['operationId' => 'x', 'parameters' => [['name' => 'id', 'in' => 'body']]]]]),
            'needs a "name" and an "in"',
            '/paths/~1things/get/parameters/0',
        ];
        yield 'unsupported style' => [
            $with(['/things/{id}' => ['get' => ['operationId' => 'x', 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'style' => 'matrix']]]]]),
            'Style "matrix" is not supported',
            '/paths/~1things~1{id}/get/parameters/0',
        ];
        yield 'external reference' => [
            $with(['/things' => ['get' => ['operationId' => 'x', 'requestBody' => ['$ref' => 'bodies.yaml#/Thing']]]]),
            'leaves the document',
            '/paths/~1things/get/requestBody',
        ];
        yield 'dangling reference' => [
            $with(['/things' => ['get' => ['operationId' => 'x', 'requestBody' => ['$ref' => '#/components/requestBodies/Thing']]]]),
            'leads nowhere',
            '/components/requestBodies/Thing',
        ];
        yield 'reference cycle' => [
            $with(
                ['/things' => ['get' => ['operationId' => 'x', 'requestBody' => ['$ref' => '#/components/requestBodies/A']]]],
                ['components' => ['requestBodies' => ['A' => ['$ref' => '#/components/requestBodies/B'], 'B' => ['$ref' => '#/components/requestBodies/A']]]],
            ),
            'form a cycle',
            '/components/requestBodies/B',
        ];
        yield 'invalid status' => [
            $with(['/things' => ['get' => ['operationId' => 'x', 'responses' => ['ok' => ['description' => 'OK']]]]]),
            'is not a status code',
            '/paths/~1things/get/responses/ok',
        ];
        yield 'unknown security scheme' => [
            $with(['/things' => ['get' => ['operationId' => 'x', 'security' => [['oauth' => []]]]]]),
            'Security scheme "oauth" is not declared',
            '/paths/~1things/get',
        ];
        yield 'security scheme without type' => [
            $with([], ['components' => ['securitySchemes' => ['key' => ['in' => 'header']]]]),
            'needs a "type"',
            '/components/securitySchemes/key',
        ];
    }

    public function testADescriptionGivenAsArraysIsAccepted(): void
    {
        $contract = self::contractWith(['parameters' => [['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']]]]);

        self::assertSame('test', $contract->operation('test')->id);
        self::assertSame('integer', $contract->document->paths->{'/things'}->get->parameters[0]->schema->type);
    }

    public function testStatusRangesAreCaseInsensitive(): void
    {
        $contract = self::contractWith(['responses' => ['2xx' => ['description' => 'OK'], 'Default' => ['description' => 'Error']]]);

        self::assertSame(['2XX', 'default'], array_keys($contract->operation('test')->responses));
    }
}
