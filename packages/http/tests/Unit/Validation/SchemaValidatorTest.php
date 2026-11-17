<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Unit\Validation;

use Acta\Http\Exception\ContractError;
use Acta\Http\Tests\HttpTestCase;
use Acta\Http\Validation\RequestValidator;
use Acta\Http\Validation\SchemaValidator;
use Opis\JsonSchema\Validator;
use stdClass;

final class SchemaValidatorTest extends HttpTestCase
{
    private const string WEBHOOK = '/webhooks/accountOpened/post/requestBody/content/application~1json/schema';

    public function testAnyValueCanBeValidatedAgainstAnySchemaOfTheContract(): void
    {
        $schemas = new SchemaValidator(self::contract());

        self::assertSame([], $schemas->validate(SchemaValidator::toJson(['accountId' => self::ACCOUNT_ID, 'owner' => 'Ada']), self::WEBHOOK));
        self::assertSame(
            ['/accountId' => ["The data must match the 'uuid' format"], '/owner' => ['The data (integer) must match the type: string']],
            $schemas->validate(SchemaValidator::toJson(['accountId' => 'nope', 'owner' => 7]), self::WEBHOOK),
        );
        self::assertSame(
            ['' => ['The data (array) must match the type: object']],
            $schemas->validate([], '/components/schemas/Money'),
        );
    }

    public function testTheNumberOfCollectedErrorsIsLimited(): void
    {
        $schemas = new SchemaValidator(self::contract(), maxErrors: 1);

        self::assertCount(1, $schemas->validate(SchemaValidator::toJson(['accountId' => 'nope', 'owner' => 7]), self::WEBHOOK));
    }

    public function testASchemaThatDoesNotExistIsADefect(): void
    {
        $this->expectException(ContractError::class);
        $this->expectExceptionMessage('(at #/components/schemas/Missing)');

        new SchemaValidator(self::contract())->validate(1, '/components/schemas/Missing');
    }

    public function testArraysAreConvertedToTheFormJsonSchemaExpects(): void
    {
        $json = SchemaValidator::toJson(['list' => [1, ['a' => true]], 'empty' => [], 'scalar' => 'x']);

        self::assertInstanceOf(stdClass::class, $json);
        self::assertSame([], $json->empty);
        self::assertIsArray($json->list);
        self::assertEquals((object) ['a' => true], $json->list[1]);
        self::assertSame('x', SchemaValidator::toJson('x'));
        self::assertEquals(new stdClass(), SchemaValidator::toJson([], emptyArrayIsObject: true));
        self::assertSame([], SchemaValidator::toJson([]));
    }

    public function testAConfiguredValidatorAddsFormats(): void
    {
        $opis = new Validator();
        $opis->parser()->getFormatResolver()?->registerCallable(
            'string',
            'iban',
            static fn(string $value): bool => preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $value) === 1,
        );
        $contract = self::contractWith(['parameters' => [['name' => 'iban', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'iban']]]]);
        $validator = new RequestValidator($contract, new SchemaValidator($contract, $opis));
        $valid = self::request('GET', '/things?iban=FR7630006000011234567890189');
        $invalid = self::request('GET', '/things?iban=nope');

        self::assertSame(['iban' => 'FR7630006000011234567890189'], $validator->validate($valid, self::routed($valid, $contract))->query);

        $this->expectExceptionMessage('The request does not match the contract: 1 error.');

        $validator->validate($invalid, self::routed($invalid, $contract));
    }
}
