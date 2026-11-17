<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit\Serializer;

use Acta\EventSourcing\Exception\SerializationFailed;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\MessageTypeMap;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\Serializer\MessageSerializer;
use Acta\EventSourcing\Serializer\ReflectionPayloadSerializer;
use Acta\EventSourcing\Serializer\SerializedMessage;
use Acta\EventSourcing\Serializer\Upcaster;
use Acta\EventSourcing\Serializer\UpcasterChain;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountClosed;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountId;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountOpened;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountType;
use Acta\EventSourcing\Tests\Fixture\Banking\Channel;
use Acta\EventSourcing\Tests\Fixture\Banking\MoneyDeposited;
use Acta\EventSourcing\Tests\Fixture\Banking\StatementIssued;
use DateTimeImmutable;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;

final class SerializerTest extends TestCase
{
    public function testItSerializesValueObjectsEnumsAndDates(): void
    {
        $serializer = new ReflectionPayloadSerializer();
        $id = AccountId::generate();
        $event = new MoneyDeposited(
            $id,
            Money::USD(150),
            Channel::Online,
            new DateTimeImmutable('2026-02-03T04:05:06.123456+02:00'),
        );

        $payload = $serializer->serialize($event);

        self::assertSame(
            [
                'accountId' => $id->value,
                'money' => ['amount' => '150', 'currency' => ['code' => 'USD']],
                'channel' => 'Online',
                'valueDate' => '2026-02-03T04:05:06.123456+02:00',
            ],
            $payload,
        );
        self::assertEquals($event, $serializer->deserialize(MoneyDeposited::class, $payload));
        self::assertEquals($event, $serializer->deserialize(MoneyDeposited::class, self::throughJson($payload)));
    }

    public function testItRebuildsListsOfObjectsAndKeepsPlainArrays(): void
    {
        $serializer = new ReflectionPayloadSerializer();
        $event = new StatementIssued(
            AccountId::generate(),
            [Money::EUR(10), Money::USD(20)],
            2.0,
            ['tags' => ['a', 'b'], 'pages' => 3],
        );

        $payload = self::throughJson($serializer->serialize($event));

        self::assertSame(2, $payload['rate']);
        self::assertEquals($event, $serializer->deserialize(StatementIssued::class, $payload));
    }

    public function testMissingFieldsFallBackToDefaultsOrNull(): void
    {
        $serializer = new ReflectionPayloadSerializer();
        $id = AccountId::generate();

        $opened = $serializer->deserialize(AccountOpened::class, ['accountId' => $id->value, 'owner' => 'Ada']);
        $deposited = $serializer->deserialize(MoneyDeposited::class, ['accountId' => $id->value, 'money' => ['amount' => 5, 'currency' => ['code' => 'EUR']]]);

        self::assertEquals(new Currency('EUR'), $opened->currency);
        self::assertSame(AccountType::Checking, $opened->type);
        self::assertNull($deposited->channel);
        self::assertNull($deposited->valueDate);
        self::assertEquals(Money::EUR(5), $deposited->money);
    }

    public function testValuesThatAlreadyAreObjectsAreKept(): void
    {
        $id = AccountId::generate();
        $money = Money::USD(5);

        $deposited = new ReflectionPayloadSerializer()->deserialize(MoneyDeposited::class, [
            'accountId' => $id,
            'money' => $money,
            'channel' => Channel::Branch,
        ]);

        self::assertSame($id, $deposited->accountId);
        self::assertSame($money, $deposited->money);
        self::assertSame(Channel::Branch, $deposited->channel);
    }

    public function testAMissingRequiredFieldIsAnError(): void
    {
        $this->expectException(SerializationFailed::class);
        $this->expectExceptionMessage('"owner" is missing');

        new ReflectionPayloadSerializer()->deserialize(AccountOpened::class, ['accountId' => 'a']);
    }

    public function testAnInvalidValueIsReportedAsASerializationFailure(): void
    {
        $this->expectException(SerializationFailed::class);
        $this->expectExceptionMessage(AccountOpened::class);

        new ReflectionPayloadSerializer()->deserialize(
            AccountOpened::class,
            ['accountId' => 'a', 'owner' => 'Ada', 'type' => 'unknown'],
        );
    }

    public function testAMessageCanControlItsOwnPayload(): void
    {
        $serializer = new ReflectionPayloadSerializer();
        $event = new AccountClosed(AccountId::fromString('acc-1'), 'fraud');

        self::assertSame(['account' => 'acc-1', 'why' => 'fraud'], $serializer->serialize($event));
        self::assertEquals($event, $serializer->deserialize(AccountClosed::class, ['account' => 'acc-1', 'why' => 'fraud']));
    }

    public function testConstructorParametersWithoutPropertyAreRefused(): void
    {
        $message = new class ('x') {
            public string $other;

            public function __construct(string $value)
            {
                $this->other = $value;
            }
        };

        $this->expectException(SerializationFailed::class);
        $this->expectExceptionMessage('$value');

        new ReflectionPayloadSerializer()->serialize($message);
    }

    public function testAnEnvelopeSurvivesARoundTrip(): void
    {
        $types = new MessageTypeMap(AccountOpened::class);
        $serializer = new MessageSerializer($types);
        $envelope = new EnvelopeFactory($types)->wrap(
            new AccountOpened(AccountId::generate(), 'Ada', new Currency('USD'), AccountType::Savings),
            new Metadata(['tenant' => 'acme']),
        );

        $serialized = $serializer->serialize($envelope);
        $restored = $serializer->deserialize(SerializedMessage::fromArray(self::throughJson($serialized->toArray())));

        self::assertSame('banking.account-opened', $serialized->name);
        self::assertSame(2, $serialized->version);
        self::assertCount(1, $restored);
        self::assertEquals($envelope, $restored[0]);
    }

    public function testStoredMessagesAreUpcastBeforeBeingRebuilt(): void
    {
        $addCurrency = new class implements Upcaster {
            public function upcast(SerializedMessage $message): iterable
            {
                if ($message->name !== 'banking.account-opened' || $message->version !== 1) {
                    return [$message];
                }

                return [$message->withPayload([...$message->payload, 'currency' => ['code' => 'USD']], version: 2)];
            }
        };
        $rename = new class implements Upcaster {
            public function upcast(SerializedMessage $message): iterable
            {
                return $message->name === 'legacy.account-created'
                    ? [$message->withName('banking.account-opened')->withMetadata([...$message->metadata, 'upcast' => true])]
                    : [$message];
            }
        };
        $dropNoise = new class implements Upcaster {
            public function upcast(SerializedMessage $message): iterable
            {
                return $message->name === 'legacy.noise' ? [] : [$message];
            }
        };

        $serializer = new MessageSerializer(
            new MessageTypeMap(AccountOpened::class),
            upcaster: new UpcasterChain($dropNoise, $rename, $addCurrency),
        );
        $stored = fn(string $name): SerializedMessage => new SerializedMessage(
            '018f4e3a-7b2c-7d1e-9a0b-1c2d3e4f5a6b',
            $name,
            1,
            ['accountId' => 'acc-1', 'owner' => 'Ada'],
            [],
            '2020-01-01T00:00:00.000000+00:00',
        );

        $restored = $serializer->deserialize($stored('legacy.account-created'));

        self::assertCount(1, $restored);
        self::assertEquals(new AccountOpened(AccountId::fromString('acc-1'), 'Ada', new Currency('USD')), $restored[0]->message);
        self::assertSame('banking.account-opened', $restored[0]->name);
        self::assertSame(['upcast' => true], $restored[0]->metadata->values);
        self::assertSame([], $serializer->deserialize($stored('legacy.noise')));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function throughJson(array $data): array
    {
        return json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
