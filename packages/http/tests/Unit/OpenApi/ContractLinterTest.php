<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Unit\OpenApi;

use Acta\Http\OpenApi\Contract;
use Acta\Http\OpenApi\ContractLinter;
use Acta\Http\OpenApi\LintIssue;
use Acta\Http\Testing\ContractAssertions;
use Acta\Http\Tests\HttpTestCase;

final class ContractLinterTest extends HttpTestCase
{
    public function testTheBankingContractIsClean(): void
    {
        self::assertSame([], ContractLinter::lint(self::contract()));
        self::assertSame([], ContractLinter::lintFile(__DIR__ . '/../../Fixture/openapi.yaml'));

        ContractAssertions::assertContractIsValid(self::contract());
    }

    public function testItReportsWhatThePackageReliesOn(): void
    {
        $contract = Contract::fromDocument([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test'],
            'paths' => [
                '/things' => [
                    'get' => [
                        'operationId' => 'listThings',
                        'parameters' => [['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integr']]],
                        'responses' => [
                            '200' => [
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Missing']]],
                            ],
                        ],
                    ],
                    'post' => ['operationId' => 'createThing'],
                ],
            ],
            'components' => [
                'schemas' => ['Thing' => ['properties' => ['owner' => ['$ref' => 'people.yaml#/Person']]]],
                'securitySchemes' => ['basic' => ['type' => 'http']],
            ],
        ]);

        $issues = array_map(static fn(LintIssue $issue): string => $issue->describe(), ContractLinter::lint($contract));

        self::assertContains('#/info: The description needs a title and a version.', $issues);
        self::assertContains(
            '#/paths/~1things/get/responses/200/content/application~1json/schema/$ref: Reference "#/components/schemas/Missing" leads nowhere.',
            $issues,
        );
        self::assertContains(
            '#/components/schemas/Thing/properties/owner/$ref: Reference "people.yaml#/Person" leaves the document: bundle the contract into one file.',
            $issues,
        );
        self::assertContains('#/components/securitySchemes/basic: A security scheme of type "http" needs a "scheme", such as "bearer".', $issues);
        self::assertContains('#/paths/~1things/post/responses: Operation "createThing" declares no response.', $issues);
        self::assertContains('#/paths/~1things/get/responses/200: A response needs a description.', $issues);
        self::assertNotEmpty(array_filter($issues, static fn(string $issue): bool => str_starts_with($issue, '#/paths/~1things/get/parameters/0/schema: ')));
    }

    public function testAFileThatDoesNotLoadIsOneIssue(): void
    {
        $issues = ContractLinter::lintFile(__DIR__ . '/missing.yaml');

        self::assertCount(1, $issues);
        self::assertStringContainsString('cannot be read', $issues[0]->message);
    }
}
