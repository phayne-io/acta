<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit\Message;

use Acta\EventSourcing\Exception\InvalidArgument;
use Acta\EventSourcing\Exception\UnknownMessage;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\MessageKind;
use Acta\EventSourcing\Message\MessageName;
use Acta\EventSourcing\Message\MessageTypeMap;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\Message\MetadataEnricher;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountId;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountOpened;
use Acta\EventSourcing\Tests\Fixture\Banking\GetBalance;
use Acta\EventSourcing\Tests\Fixture\Banking\OpenAccount;
use Acta\EventSourcing\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use stdClass;

final class MessageTest extends TestCase
{
    public function testMetadataIsImmutable(): void
    {
        $metadata = new Metadata(['tenant' => 'acme']);

        $extended = $metadata->with('actor', 'julien')->merge(['tenant' => 'globex'])->merge(new Metadata(['n' => 1]));

        self::assertSame(['tenant' => 'acme'], $metadata->values);
        self::assertSame(['tenant' => 'globex', 'actor' => 'julien', 'n' => 1], $extended->values);
        self::assertSame(['actor' => 'julien', 'n' => 1], $extended->without('tenant')->values);
        self::assertTrue($extended->has('actor'));
        self::assertFalse($extended->has('missing'));
        self::assertSame('fallback', $extended->get('missing', 'fallback'));
        self::assertCount(3, $extended);
    }

    public function testAnEnvelopeCarriesAMessageAndItsContext(): void
    {
        $envelope = new Envelope(
            new OpenAccount(AccountId::generate(), 'Ada'),
            Uuid::v7(),
            'banking.open-account',
            new DateTimeImmutable(),
        );

        $stamped = $envelope->withAddedMetadata('actor', 'ada')->withAddedMetadata('tenant', 'acme');

        self::assertSame(MessageKind::Command, $envelope->kind());
        self::assertCount(0, $envelope->metadata);
        self::assertSame(['actor' => 'ada', 'tenant' => 'acme'], $stamped->metadata->values);
        self::assertSame([], $stamped->withMetadata(new Metadata())->metadata->values);
        self::assertSame($envelope->message, $stamped->message);
    }

    public function testTheKindOfAMessageComesFromItsMarker(): void
    {
        $id = AccountId::generate();

        self::assertSame(MessageKind::Command, MessageKind::of(new OpenAccount($id, 'Ada')));
        self::assertSame(MessageKind::Query, MessageKind::of(new GetBalance($id)));
        self::assertSame(MessageKind::Event, MessageKind::of(new AccountOpened($id, 'Ada')));
        self::assertSame(MessageKind::Event, MessageKind::of(new stdClass()));
    }

    public function testMessagesAreNamedByAttributeOrByClass(): void
    {
        $types = new MessageTypeMap(AccountOpened::class);
        $id = AccountId::generate();

        self::assertSame('banking.account-opened', $types->nameOf(new AccountOpened($id, 'Ada')));
        self::assertSame(2, $types->versionOf(new AccountOpened($id, 'Ada')));
        self::assertSame(AccountOpened::class, $types->classOf('banking.account-opened'));

        self::assertSame(OpenAccount::class, $types->nameOf(new OpenAccount($id, 'Ada')));
        self::assertSame(1, $types->versionOf(new OpenAccount($id, 'Ada')));
        self::assertSame(GetBalance::class, $types->classOf(GetBalance::class));
    }

    public function testANamedMessageMustBeRegisteredToBeResolved(): void
    {
        $this->expectException(UnknownMessage::class);

        new MessageTypeMap()->classOf('banking.account-opened');
    }

    public function testAClassNameIsAlwaysAValidName(): void
    {
        // Covers events stored before their class was given a public name.
        self::assertSame(AccountOpened::class, new MessageTypeMap()->classOf(AccountOpened::class));
    }

    public function testTwoClassesCannotShareAName(): void
    {
        $duplicate = new #[MessageName('banking.account-opened')] class {};

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('banking.account-opened');

        new MessageTypeMap(AccountOpened::class, $duplicate::class);
    }

    public function testAMessageNameMustBeValid(): void
    {
        $this->expectException(InvalidArgument::class);

        new MessageName('valid', version: 0);
    }

    public function testTheFactoryStampsAndEnrichesMessages(): void
    {
        $now = new DateTimeImmutable('2026-03-04T05:06:07.891234+00:00');
        $clock = new class ($now) implements ClockInterface {
            public function __construct(private readonly DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
        $enricher = new class implements MetadataEnricher {
            public function enrich(Envelope $envelope): Envelope
            {
                return $envelope->withAddedMetadata('tenant', 'acme');
            }
        };

        $factory = new EnvelopeFactory(new MessageTypeMap(AccountOpened::class), $clock, $enricher);
        $event = new AccountOpened(AccountId::generate(), 'Ada');

        $envelope = $factory->wrap($event, new Metadata(['actor' => 'ada']));

        self::assertSame($event, $envelope->message);
        self::assertSame('banking.account-opened', $envelope->name);
        self::assertSame($now, $envelope->createdAt);
        self::assertSame(['actor' => 'ada', 'tenant' => 'acme'], $envelope->metadata->values);
        self::assertSame(
            (int) $now->format('Uv'),
            hexdec(substr(str_replace('-', '', $envelope->id->value), 0, 12)),
        );
        self::assertFalse($envelope->id->equals($factory->wrap($event)->id));
    }

    public function testWrappingAnEnvelopeKeepsItsIdentity(): void
    {
        $factory = new EnvelopeFactory();
        $envelope = $factory->wrap(new OpenAccount(AccountId::generate(), 'Ada'), new Metadata(['a' => 1]));

        self::assertSame($envelope, $factory->wrap($envelope));
        self::assertTrue($envelope->id->equals($factory->wrap($envelope, new Metadata(['b' => 2]))->id));
        self::assertSame(['a' => 1, 'b' => 2], $factory->wrap($envelope, new Metadata(['b' => 2]))->metadata->values);
    }
}
