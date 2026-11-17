<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit;

use Acta\EventSourcing\Exception\ActaException;
use Acta\EventSourcing\Exception\InvalidArgument;
use Acta\EventSourcing\SystemClock;
use Acta\EventSourcing\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testItGeneratesVersion7Identifiers(): void
    {
        $uuid = Uuid::v7();

        self::assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $uuid->value,
        );
        self::assertSame($uuid->value, (string) $uuid);
    }

    public function testIdentifiersAreUniqueAndSortByCreationTime(): void
    {
        $earlier = Uuid::v7(new DateTimeImmutable('2026-01-01T00:00:00.000+00:00'));
        $later = Uuid::v7(new DateTimeImmutable('2026-01-01T00:00:00.001+00:00'));

        self::assertLessThan($later->value, $earlier->value);
        self::assertSame(1_767_225_600_000, hexdec(substr(str_replace('-', '', $earlier->value), 0, 12)));
        self::assertCount(1000, array_unique(array_map(static fn(): string => Uuid::v7()->value, range(1, 1000))));
    }

    public function testItParsesCanonicalStrings(): void
    {
        $uuid = Uuid::fromString('018F4E3A-7B2C-7D1E-9A0B-1C2D3E4F5A6B');

        self::assertSame('018f4e3a-7b2c-7d1e-9a0b-1c2d3e4f5a6b', $uuid->value);
        self::assertTrue($uuid->equals(Uuid::fromString($uuid->value)));
        self::assertFalse($uuid->equals(Uuid::v7()));
    }

    public function testItRejectsInvalidStrings(): void
    {
        $this->expectException(InvalidArgument::class);

        Uuid::fromString('not-a-uuid');
    }

    public function testLibraryExceptionsShareAMarker(): void
    {
        try {
            Uuid::fromString('');
            self::fail('An exception was expected.');
        } catch (ActaException $exception) {
            self::assertInstanceOf(InvalidArgumentException::class, $exception);
        }
    }

    public function testTheSystemClockTellsTheTimeInUtc(): void
    {
        $now = new SystemClock()->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertEqualsWithDelta(time(), $now->getTimestamp(), 2);
    }
}
