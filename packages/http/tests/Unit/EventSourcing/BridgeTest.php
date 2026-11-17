<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Unit\EventSourcing;

use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Exception\AccessDenied;
use Acta\EventSourcing\Exception\AggregateNotFound;
use Acta\EventSourcing\Exception\ConcurrencyConflict;
use Acta\EventSourcing\Exception\SerializationFailed;
use Acta\EventSourcing\Message\Metadata;
use Acta\Http\EventSourcing\EventSourcingExceptionMapper;
use Acta\Http\EventSourcing\IfMatch;
use Acta\Http\EventSourcing\MessageFactory;
use Acta\Http\EventSourcing\MetadataFactory;
use Acta\Http\Exception\HttpException;
use Acta\Http\Tests\Fixture\Banking\AccountId;
use Acta\Http\Tests\Fixture\Banking\DepositMoney;
use Acta\Http\Tests\Fixture\Banking\OpenAccount;
use Acta\Http\Tests\HttpTestCase;
use Acta\Http\Trace\TraceContext;
use Acta\Http\Validation\Input;
use Money\Money;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class BridgeTest extends HttpTestCase
{
    public function testAMessageIsBuiltFromTheValidatedInput(): void
    {
        $input = new Input(body: ['owner' => 'Ada', 'currency' => 'USD', 'ignored' => true]);
        $id = AccountId::generate();

        $command = new MessageFactory()->create(OpenAccount::class, $input, ['accountId' => $id]);

        self::assertEquals(new OpenAccount($id, 'Ada', 'USD'), $command);
        self::assertSame($id, $command->accountId);
    }

    public function testThePathWinsOverTheQueryWhichWinsOverTheBody(): void
    {
        $input = new Input(
            path: ['accountId' => self::ACCOUNT_ID],
            query: ['reference' => 'from-query'],
            body: ['accountId' => 'spoofed', 'reference' => 'from-body', 'money' => ['amount' => '120', 'currency' => ['code' => 'EUR']]],
        );

        self::assertEquals(
            new DepositMoney(AccountId::fromString(self::ACCOUNT_ID), Money::EUR(120), 'from-query'),
            new MessageFactory()->create(DepositMoney::class, $input),
        );
    }

    public function testOverridesMayBeValueObjects(): void
    {
        $input = new Input(path: ['accountId' => self::ACCOUNT_ID], body: ['money' => ['amount' => '120', 'currency' => 'EUR']]);
        $money = Money::EUR(120);

        self::assertSame($money, new MessageFactory()->create(DepositMoney::class, $input, ['money' => $money])->money);
    }

    public function testAStringBodyBringsNoField(): void
    {
        $this->expectException(SerializationFailed::class);
        $this->expectExceptionMessage('"owner" is missing');

        new MessageFactory()->create(OpenAccount::class, new Input(path: ['accountId' => self::ACCOUNT_ID], body: 'Ada'));
    }

    public function testTheTraceOfTheRequestBecomesTheCorrelationOfItsMessages(): void
    {
        $trace = TraceContext::start();
        $request = self::request('POST', '/accounts', ['X-User' => 'ada'])->withAttribute(TraceContext::class, $trace);
        $factory = new MetadataFactory([
            'actor' => static fn(ServerRequestInterface $request): string => $request->getHeaderLine('X-User'),
            'tenant' => static fn(ServerRequestInterface $request): ?string => $request->getAttribute('tenant'),
        ]);

        self::assertSame(
            [Metadata::CORRELATION_ID => $trace->traceId, 'traceparent' => $trace->toHeader(), 'actor' => 'ada'],
            $factory->fromRequest($request)->values,
        );
        self::assertSame([], new MetadataFactory()->fromRequest(self::request('GET', '/'))->values);
    }

    public function testIfMatchCarriesTheExpectedVersion(): void
    {
        $request = self::request('POST', '/');

        self::assertSame('"7"', IfMatch::etag(7));
        self::assertEquals(ExpectedVersion::any(), IfMatch::expectedVersion($request));
        self::assertEquals(ExpectedVersion::streamExists(), IfMatch::expectedVersion($request->withHeader('If-Match', '*')));
        self::assertEquals(ExpectedVersion::exactly(7), IfMatch::expectedVersion($request->withHeader('If-Match', ' "7" ')));

        IfMatch::assert($request, 3);
        IfMatch::assert($request->withHeader('If-Match', '"3"'), 3);
        IfMatch::assert($request->withHeader('If-Match', '*'), 3);

        foreach ([['"2"', 3, 'The resource is at version 3, not at version 2. Read it again, then retry.'], ['*', 0, 'The resource does not exist.']] as [$header, $version, $detail]) {
            try {
                IfMatch::assert($request->withHeader('If-Match', $header), $version);
                self::fail('The precondition should fail.');
            } catch (HttpException $exception) {
                self::assertSame(412, $exception->status);
                self::assertSame($detail, $exception->getMessage());
            }
        }
    }

    public function testAnUnreadableIfMatchIsABadRequest(): void
    {
        foreach (['7', 'W/"7"', '"abc"', '"1", "2"'] as $header) {
            try {
                IfMatch::expectedVersion(self::request('POST', '/', ['If-Match' => $header]));
                self::fail($header . ' should be refused.');
            } catch (HttpException $exception) {
                self::assertSame(400, $exception->status);
            }
        }
    }

    public function testExceptionsOfTheWriteSideGetAStatus(): void
    {
        $mapper = new EventSourcingExceptionMapper();
        $conflict = ConcurrencyConflict::versionMismatch(StreamName::for('account', 'a'), ExpectedVersion::exactly(1), 2);

        self::assertSame(404, $mapper->map(new AggregateNotFound('Account a does not exist.'))?->status);
        self::assertSame(409, $mapper->map($conflict)?->status);
        self::assertSame($conflict, $mapper->map($conflict)?->getPrevious());
        self::assertSame(403, $mapper->map(new AccessDenied('Not allowed.'))?->status);
        self::assertNull($mapper->map(new RuntimeException('Something else.')));

        // What the client reads does not echo internal messages.
        self::assertSame('The resource does not exist.', $mapper->map(new AggregateNotFound('Stream account-a is empty.'))?->getMessage());
    }
}
