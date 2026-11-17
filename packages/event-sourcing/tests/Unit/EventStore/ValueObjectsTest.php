<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit\EventStore;

use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\MetadataMatcher;
use Acta\EventSourcing\EventStore\Operator;
use Acta\EventSourcing\EventStore\Position;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Exception\InvalidArgument;
use Acta\EventSourcing\Message\Metadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueObjectsTest extends TestCase
{
    public function testAStreamNameIsMadeOfACategoryAndAnIdentifier(): void
    {
        $stream = StreamName::for('account', '018f4e3a-7b2c-7d1e-9a0b-1c2d3e4f5a6b');

        self::assertSame('account-018f4e3a-7b2c-7d1e-9a0b-1c2d3e4f5a6b', (string) $stream);
        self::assertSame('account', $stream->category);
        self::assertSame('018f4e3a-7b2c-7d1e-9a0b-1c2d3e4f5a6b', $stream->id);
        self::assertTrue($stream->equals(StreamName::fromString($stream->value)));

        $plain = StreamName::fromString('audit');

        self::assertSame('audit', $plain->category);
        self::assertNull($plain->id);
        self::assertFalse($plain->equals($stream));
    }

    /**
     * @return iterable<string, array{callable(): mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'empty stream name' => [static fn() => StreamName::fromString('')];
        yield 'stream name with whitespace' => [static fn() => StreamName::fromString('a b')];
        yield 'stream name too long' => [static fn() => StreamName::fromString(str_repeat('a', 256))];
        yield 'category with dash' => [static fn() => StreamName::for('bank-account', '1')];
        yield 'empty category' => [static fn() => StreamName::for('', '1')];
        yield 'negative expected version' => [static fn() => ExpectedVersion::exactly(-1)];
        yield 'negative position' => [static fn() => new Position(0, -1)];
        yield 'malformed position' => [static fn() => Position::fromString('12')];
        yield 'empty metadata field' => [static fn() => MetadataMatcher::where('', Operator::Equals, 1)];
        yield 'list expected by in' => [static fn() => MetadataMatcher::where('a', Operator::In, 'x')];
        yield 'string expected by regex' => [static fn() => MetadataMatcher::where('a', Operator::Regex, 1)];
        yield 'scalar expected by equals' => [static fn() => MetadataMatcher::where('a', Operator::Equals, [1])];
        yield 'number or string expected by comparison' => [static fn() => MetadataMatcher::where('a', Operator::GreaterThan, true)];
    }

    /**
     * @param callable(): mixed $build
     */
    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRejected(callable $build): void
    {
        $this->expectException(InvalidArgument::class);

        $build();
    }

    public function testExpectedVersions(): void
    {
        self::assertTrue(ExpectedVersion::any()->isSatisfiedBy(0));
        self::assertTrue(ExpectedVersion::any()->isSatisfiedBy(7));
        self::assertTrue(ExpectedVersion::noStream()->isSatisfiedBy(0));
        self::assertFalse(ExpectedVersion::noStream()->isSatisfiedBy(1));
        self::assertTrue(ExpectedVersion::streamExists()->isSatisfiedBy(1));
        self::assertFalse(ExpectedVersion::streamExists()->isSatisfiedBy(0));
        self::assertTrue(ExpectedVersion::exactly(3)->isSatisfiedBy(3));
        self::assertFalse(ExpectedVersion::exactly(3)->isSatisfiedBy(4));
        self::assertTrue(ExpectedVersion::exactly(0)->isSatisfiedBy(0));

        self::assertSame(
            ['any version', 'no stream', 'an existing stream', 'version 3'],
            array_map(
                static fn(ExpectedVersion $expected): string => $expected->describe(),
                [ExpectedVersion::any(), ExpectedVersion::noStream(), ExpectedVersion::streamExists(), ExpectedVersion::exactly(3)],
            ),
        );
    }

    public function testPositionsAreOrderedByTransactionThenSequence(): void
    {
        $start = Position::start();
        $early = new Position(10, 50);
        $late = new Position(11, 40);

        self::assertTrue($early->isAfter($start));
        self::assertTrue($late->isAfter($early));
        self::assertFalse($early->isAfter($late));
        self::assertTrue(new Position(10, 51)->isAfter($early));
        self::assertFalse($early->isAfter($early));
        self::assertTrue($early->equals(Position::fromString((string) $early)));
        self::assertSame('10:50', (string) $early);
        self::assertSame(0, $start->compare(new Position()));
    }

    public function testOperatorsFollowTheSameRulesAsTheSqlAdapters(): void
    {
        self::assertTrue(Operator::Equals->test(1, 1.0));
        self::assertFalse(Operator::Equals->test('1', 1));
        self::assertTrue(Operator::Equals->test(null, null));
        self::assertTrue(Operator::NotEquals->test(null, 'a'));
        self::assertTrue(Operator::GreaterThan->test(2, 1.5));
        self::assertTrue(Operator::GreaterThanOrEqual->test('b', 'b'));
        self::assertFalse(Operator::GreaterThan->test('10', 9));
        self::assertFalse(Operator::LowerThan->test(null, 1));
        self::assertTrue(Operator::LowerThanOrEqual->test(1, 1));
        self::assertTrue(Operator::In->test('a', ['a', 'b']));
        self::assertFalse(Operator::In->test('c', []));
        self::assertTrue(Operator::NotIn->test(null, ['a']));
        self::assertTrue(Operator::Regex->test('eu~west', '^eu~'));
        self::assertFalse(Operator::Regex->test(12, '1'));
    }

    public function testAMatcherCombinesItsConditions(): void
    {
        $matcher = MetadataMatcher::where('tenant', Operator::Equals, 'acme')->and('attempt', Operator::LowerThan, 3);

        self::assertCount(2, $matcher->conditions);
        self::assertTrue($matcher->matches(new Metadata(['tenant' => 'acme', 'attempt' => 2])));
        self::assertFalse($matcher->matches(new Metadata(['tenant' => 'acme', 'attempt' => 3])));
        self::assertFalse($matcher->matches(new Metadata(['attempt' => 1])));
    }
}
