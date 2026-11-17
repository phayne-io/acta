<?php

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure;

use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Message\DomainEvent;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\Http\OpenApi\ContractLoader;
use Acta\Webhook\Bridge\ContractPayloadValidator;
use App\Banking\Domain\AccountId;
use App\Banking\Domain\Event\AccountOpened;
use App\Banking\Domain\Event\MoneyDeposited;
use App\Banking\Domain\Event\MoneyWithdrawn;
use App\Banking\Infrastructure\Secondary\Webhook\PublicEvents;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the bank publishes to webhook subscribers, checked against what the contract
 * promises them.
 */
#[CoversClass(PublicEvents::class)]
final class PublicEventsTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('domainEvents')]
    public function testADomainEventIsPublishedAsTheContractDescribesIt(DomainEvent $event, string $type, array $data): void
    {
        $published = new PublicEvents()->map(self::record($event));

        self::assertNotNull($published);
        self::assertSame($type, $published->type);
        self::assertSame($data, $published->data);

        $validator = new ContractPayloadValidator(ContractLoader::load(dirname(__DIR__, 3) . '/openapi/openapi.yaml'));
        $notification = ['id' => 'evt_1', 'type' => $type, 'timestamp' => '2026-01-01T09:00:00.000+00:00', 'data' => $data];

        self::assertSame([], $validator->validate($type, $notification));
    }

    /**
     * @return iterable<string, array{DomainEvent, string, array<string, mixed>}>
     */
    public static function domainEvents(): iterable
    {
        $id = AccountId::fromString('01a1eb64-87b8-7527-99d9-acfbd5ff1517');
        $amount = ['amount' => '12000', 'currency' => 'EUR'];

        yield 'account.opened' => [
            new AccountOpened($id, 'Ada', new Currency('EUR')),
            'account.opened',
            ['accountId' => $id->value, 'owner' => 'Ada', 'currency' => 'EUR'],
        ];
        yield 'account.credited' => [new MoneyDeposited($id, Money::EUR(12000)), 'account.credited', ['accountId' => $id->value, 'amount' => $amount]];
        yield 'account.debited' => [new MoneyWithdrawn($id, Money::EUR(12000)), 'account.debited', ['accountId' => $id->value, 'amount' => $amount]];
    }

    public function testOtherEventsStayInside(): void
    {
        self::assertNull(new PublicEvents()->map(self::record(new class implements DomainEvent {})));
    }

    private static function record(DomainEvent $event): RecordedEvent
    {
        return new InMemoryEventStore()->append(StreamName::for('test', '1'), ExpectedVersion::any(), new EnvelopeFactory()->wrap($event))[0];
    }
}
