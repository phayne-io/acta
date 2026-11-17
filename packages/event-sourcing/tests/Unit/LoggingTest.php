<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit;

use Acta\EventSourcing\Bus\DefaultCommandBus;
use Acta\EventSourcing\Bus\DefaultQueryBus;
use Acta\EventSourcing\Bus\HandlerMap;
use Acta\EventSourcing\Bus\Middleware\CausationMiddleware;
use Acta\EventSourcing\Bus\Middleware\LoggingMiddleware;
use Acta\EventSourcing\Bus\Middleware\RetryOnConflictMiddleware;
use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Exception\ConcurrencyConflict;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\MessageTypeMap;
use Acta\EventSourcing\Projection\InMemoryProjectionStore;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\EventSourcing\Projection\ProjectionOptions;
use Acta\EventSourcing\Projection\Projector;
use Acta\EventSourcing\SystemClock;
use Acta\EventSourcing\Testing\Fixture\CounterIncremented;
use Acta\EventSourcing\Testing\Fixture\CounterStarted;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountId;
use Acta\EventSourcing\Tests\Fixture\Banking\GetBalance;
use Acta\EventSourcing\Tests\Fixture\Banking\OpenAccount;
use Acta\EventSourcing\Tests\Fixture\RecordingLogger;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * What the library reports to a PSR-3 logger.
 */
final class LoggingTest extends TestCase
{
    private RecordingLogger $logger;

    #[Override]
    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testAHandledMessageIsLoggedWithItsContext(): void
    {
        $causation = new CausationMiddleware();
        $envelopes = new EnvelopeFactory(new MessageTypeMap(), new SystemClock(), $causation);
        $bus = new DefaultCommandBus(
            new HandlerMap()->on(OpenAccount::class, static fn(): null => null),
            $envelopes,
            $causation,
            new LoggingMiddleware($this->logger),
        );

        $bus->dispatch(new OpenAccount(AccountId::generate(), 'Ada Lovelace'));

        $context = $this->logger->contexts(LogLevel::INFO)[0];

        self::assertCount(1, $this->logger->records);
        self::assertStringStartsWith('Handled command ' . OpenAccount::class . ' in ', $this->logger->messages(LogLevel::INFO)[0]);
        self::assertSame(OpenAccount::class, $context['message']);
        self::assertSame('command', $context['kind']);
        self::assertSame($context['message_id'], $context['correlation_id']);
        self::assertArrayNotHasKey('causation_id', $context);
        self::assertIsFloat($context['duration_ms']);
        // The payload is never logged.
        self::assertStringNotContainsString('Ada Lovelace', (string) json_encode($this->logger->records));
    }

    public function testTheLevelOfHandledMessagesIsConfigurable(): void
    {
        $bus = new DefaultQueryBus(
            new HandlerMap()->on(GetBalance::class, static fn(): int => 42),
            new EnvelopeFactory(),
            new LoggingMiddleware($this->logger, LogLevel::DEBUG),
        );

        self::assertSame(42, $bus->ask(new GetBalance(AccountId::generate())));
        self::assertCount(1, $this->logger->messages(LogLevel::DEBUG));
        self::assertSame('query', $this->logger->contexts(LogLevel::DEBUG)[0]['kind']);
    }

    public function testAFailedMessageIsLoggedAsAnErrorAndRethrown(): void
    {
        $failure = new RuntimeException('boom');
        $bus = new DefaultCommandBus(
            new HandlerMap()->on(OpenAccount::class, static fn() => throw $failure),
            new EnvelopeFactory(),
            new LoggingMiddleware($this->logger),
        );

        try {
            $bus->dispatch(new OpenAccount(AccountId::generate(), 'Ada'));
            self::fail('The failure of the handler should be rethrown.');
        } catch (RuntimeException $thrown) {
            self::assertSame($failure, $thrown);
        }

        self::assertCount(1, $this->logger->records);
        self::assertStringEndsWith('ms: boom', $this->logger->messages(LogLevel::ERROR)[0]);
        self::assertSame($failure, $this->logger->contexts(LogLevel::ERROR)[0]['exception']);
    }

    public function testEachRetryIsLoggedAsAWarning(): void
    {
        $attempts = 0;
        $handlers = new HandlerMap()->on(OpenAccount::class, static function () use (&$attempts): void {
            if (++$attempts < 3) {
                throw ConcurrencyConflict::versionMismatch(StreamName::for('account', 'a'), ExpectedVersion::exactly(1), 2);
            }
        });
        $bus = new DefaultCommandBus(
            $handlers,
            new EnvelopeFactory(),
            new LoggingMiddleware($this->logger),
            new RetryOnConflictMiddleware(3, logger: $this->logger),
        );

        $bus->dispatch(new OpenAccount(AccountId::generate(), 'Ada'));

        $warnings = $this->logger->messages(LogLevel::WARNING);

        self::assertCount(2, $warnings);
        self::assertStringContainsString('(attempt 2)', $warnings[0]);
        self::assertStringContainsString('(attempt 3)', $warnings[1]);
        self::assertInstanceOf(ConcurrencyConflict::class, $this->logger->contexts(LogLevel::WARNING)[0]['exception']);
        // The logging middleware sits outside the retries: one record for the message.
        self::assertCount(1, $this->logger->messages(LogLevel::INFO));
    }

    public function testAProjectionReportsItsLifecycle(): void
    {
        $store = new InMemoryEventStore();
        $envelopes = new EnvelopeFactory();
        $projections = new ProjectionManager($store, new InMemoryProjectionStore(), $store, $envelopes, $this->logger);
        $store->append(
            StreamName::for('counter', 'a'),
            ExpectedVersion::noStream(),
            $envelopes->wrap(new CounterStarted('a')),
            $envelopes->wrap(new CounterIncremented('a', 1)),
            $envelopes->wrap(new CounterIncremented('a', 2)),
        );
        $build = static fn(): Projector => $projections
            ->projection('totals', new ProjectionOptions(batchSize: 2))
            ->fromAll()
            ->whenAny(static fn() => null);

        $build()->run(keepRunning: false);

        self::assertSame(
            ['Projection totals started at position 0:0.', 'Projection totals stopped at position 0:3.'],
            $this->logger->messages(LogLevel::INFO),
        );
        self::assertSame(
            [
                'Projection totals handled 2 event(s), now at position 0:2.',
                'Projection totals handled 1 event(s), now at position 0:3.',
            ],
            $this->logger->messages(LogLevel::DEBUG),
        );

        $projector = $build();
        $projector->reset();
        $projector->delete(deleteEmittedEvents: true);

        self::assertSame(
            ['Projection totals was reset.', 'Projection totals was deleted.'],
            $this->logger->messages(LogLevel::NOTICE),
        );
        self::assertTrue($this->logger->contexts(LogLevel::NOTICE)[1]['deleted_emitted_events']);
    }

    public function testAFailingProjectionIsLoggedAsAnError(): void
    {
        $store = new InMemoryEventStore();
        $envelopes = new EnvelopeFactory();
        $projections = new ProjectionManager($store, new InMemoryProjectionStore(), $store, $envelopes, $this->logger);
        $store->append(StreamName::for('counter', 'a'), ExpectedVersion::noStream(), $envelopes->wrap(new CounterStarted('a')));
        $failure = new RuntimeException('boom');

        try {
            $projections->projection('broken')->fromAll()->whenAny(static fn() => throw $failure)->run(keepRunning: false);
            self::fail('The failure of the handler should be rethrown.');
        } catch (RuntimeException $thrown) {
            self::assertSame($failure, $thrown);
        }

        self::assertCount(1, $this->logger->messages(LogLevel::ERROR));
        self::assertStringContainsString('Projection broken failed', $this->logger->messages(LogLevel::ERROR)[0]);
        self::assertSame($failure, $this->logger->contexts(LogLevel::ERROR)[0]['exception']);
        self::assertSame('broken', $this->logger->contexts(LogLevel::ERROR)[0]['projection']);
    }
}
