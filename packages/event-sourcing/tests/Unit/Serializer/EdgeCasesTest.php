<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit\Serializer;

use Acta\EventSourcing\Exception\SerializationFailed;
use Acta\EventSourcing\Postgres\Row;
use Acta\EventSourcing\Serializer\ReflectionPayloadSerializer;
use Acta\EventSourcing\Serializer\SerializedMessage;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountClosed;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountId;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Less common shapes the reflection serializer and the row helpers must cope with.
 */
final class EdgeCasesTest extends TestCase
{
    public function testDatesAreRebuiltWithTheDeclaredType(): void
    {
        $serializer = new ReflectionPayloadSerializer();
        $message = new class (new DateTime('2026-05-06T07:08:09.000000+00:00'), new DateTimeImmutable('2026-01-01T00:00:00+01:00')) {
            public function __construct(
                public DateTime $mutable,
                public DateTimeInterface $any,
            ) {}
        };

        $restored = $serializer->deserialize($message::class, $serializer->serialize($message));

        self::assertEquals($message->mutable, $restored->mutable);
        self::assertInstanceOf(DateTimeImmutable::class, $restored->any);
        self::assertEquals($message->any, $restored->any);
    }

    public function testNestedSelfDescribingObjectsAndUntypedValuesAreSupported(): void
    {
        $serializer = new ReflectionPayloadSerializer();
        $message = new class (new AccountClosed(AccountId::fromString('acc-1'), 'fraud'), ['free' => 'form'], 12, 'either') {
            /**
             * @param mixed $untyped
             */
            public function __construct(
                public AccountClosed $closed,
                public $untyped,
                public int|float $number,
                public int|string $union,
            ) {}
        };

        $payload = $serializer->serialize($message);

        self::assertSame(['account' => 'acc-1', 'why' => 'fraud'], $payload['closed']);
        self::assertEquals($message, $serializer->deserialize($message::class, $payload));
    }

    public function testObjectsWithANonPublicConstructorAreRebuilt(): void
    {
        $serializer = new ReflectionPayloadSerializer();
        $message = PrivatelyBuilt::of('x', 3);

        $restored = $serializer->deserialize(PrivatelyBuilt::class, $serializer->serialize($message));

        self::assertEquals($message, $restored);
        self::assertEquals(new stdClass(), $serializer->deserialize(stdClass::class, []));
        self::assertSame([], $serializer->serialize(new stdClass()));
    }

    /**
     * @return iterable<string, array{callable(ReflectionPayloadSerializer): mixed}>
     */
    public static function unsupportedShapes(): iterable
    {
        yield 'a resource' => [static fn(ReflectionPayloadSerializer $serializer) => $serializer->serialize(
            new class (fopen('php://memory', 'r')) {
                public function __construct(public mixed $handle) {}
            },
        )];

        $composite = new class (null) {
            public function __construct(public AccountId|AccountClosed|null $either) {}
        };

        yield 'an object behind a union type' => [static fn(ReflectionPayloadSerializer $serializer) => $serializer->deserialize(
            $composite::class,
            ['either' => ['account' => 'acc-1', 'why' => 'x']],
        )];

        $typed = new class (AccountId::fromString('a')) {
            public function __construct(public AccountId $id) {}
        };

        yield 'a value of the wrong kind' => [static fn(ReflectionPayloadSerializer $serializer) => $serializer->deserialize(
            $typed::class,
            ['id' => 12],
        )];
    }

    /**
     * @param callable(ReflectionPayloadSerializer): mixed $attempt
     */
    #[DataProvider('unsupportedShapes')]
    public function testUnsupportedShapesAreReportedClearly(callable $attempt): void
    {
        $this->expectException(SerializationFailed::class);

        $attempt(new ReflectionPayloadSerializer());
    }

    public function testASerializedMessageIsValidatedWhenRebuiltFromAnArray(): void
    {
        $valid = ['id' => 'i', 'name' => 'n', 'created_at' => '2026-01-01T00:00:00+00:00'];

        self::assertSame(1, SerializedMessage::fromArray($valid)->version);
        self::assertSame([], SerializedMessage::fromArray($valid)->payload);
        self::assertSame(['0' => 'a'], SerializedMessage::fromArray([...$valid, 'metadata' => ['a']])->metadata);

        $failures = 0;

        foreach ([['name' => 'n'], [...$valid, 'version' => '2'], [...$valid, 'payload' => 'text']] as $invalid) {
            try {
                SerializedMessage::fromArray($invalid);
            } catch (SerializationFailed) {
                $failures++;
            }
        }

        self::assertSame(3, $failures);
    }

    public function testDatabaseRowsAreReadWithTheirExpectedTypes(): void
    {
        $row = ['n' => '42', 'i' => 7, 's' => 'text', 'j' => '{"a":1}', 'l' => '[1,2]', 'bad' => '{', 'scalar' => '3', 'f' => 1.5];

        self::assertSame(42, Row::int($row, 'n'));
        self::assertSame(7, Row::int($row, 'i'));
        self::assertSame('text', Row::string($row, 's'));
        self::assertSame(['a' => 1], Row::jsonObject($row, 'j'));
        self::assertSame([1, 2], Row::json($row, 'l'));
        self::assertSame('{}', Row::encode([]));
        self::assertSame('{"a":"é/1","b":1.0}', Row::encode(['a' => 'é/1', 'b' => 1.0]));

        $failures = 0;
        $attempts = [
            static fn() => Row::int($row, 's'),
            static fn() => Row::int($row, 'missing'),
            static fn() => Row::string($row, 'i'),
            static fn() => Row::json($row, 'bad'),
            static fn() => Row::json($row, 'scalar'),
            static fn() => Row::int($row, 'f'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (SerializationFailed) {
                $failures++;
            }
        }

        self::assertSame(count($attempts), $failures);
    }
}
