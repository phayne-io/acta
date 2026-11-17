<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit\Bus;

use Acta\EventSourcing\Bus\Authorizer;
use Acta\EventSourcing\Bus\DefaultCommandBus;
use Acta\EventSourcing\Bus\HandlerMap;
use Acta\EventSourcing\Bus\MessageProducer;
use Acta\EventSourcing\Bus\Middleware\AsyncMiddleware;
use Acta\EventSourcing\Bus\Middleware\AuthorizationMiddleware;
use Acta\EventSourcing\Bus\Middleware\CausationMiddleware;
use Acta\EventSourcing\Bus\Middleware\RetryOnConflictMiddleware;
use Acta\EventSourcing\Bus\Middleware\SequentialDispatchMiddleware;
use Acta\EventSourcing\Bus\Middleware\TransactionMiddleware;
use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Exception\AccessDenied;
use Acta\EventSourcing\Exception\ConcurrencyConflict;
use Acta\EventSourcing\Exception\InvalidArgument;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\MessageTypeMap;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\SystemClock;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountId;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountOpened;
use Acta\EventSourcing\Tests\Fixture\Banking\DepositMoney;
use Acta\EventSourcing\Tests\Fixture\Banking\OpenAccount;
use Acta\EventSourcing\Tests\Fixture\Banking\SendWelcomeLetter;
use Money\Money;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class MiddlewareTest extends TestCase
{
    public function testACommandIsHandledInATransaction(): void
    {
        $store = new InMemoryEventStore();
        $envelopes = new EnvelopeFactory();
        $stream = StreamName::for('account', 'a');
        $handlers = new HandlerMap()
            ->on(OpenAccount::class, static function (OpenAccount $command) use ($store, $envelopes, $stream): void {
                self::assertTrue($store->inTransaction());
                $store->append($stream, ExpectedVersion::any(), $envelopes->wrap(new AccountOpened($command->accountId, 'Ada')));
            })
            ->on(DepositMoney::class, static function () use ($store, $envelopes, $stream): void {
                $store->append($stream, ExpectedVersion::any(), $envelopes->wrap(new stdClass()));

                throw new RuntimeException('boom');
            });
        $bus = new DefaultCommandBus($handlers, $envelopes, new TransactionMiddleware($store));
        $id = AccountId::generate();

        $bus->dispatch(new OpenAccount($id, 'Ada'));

        try {
            $bus->dispatch(new DepositMoney($id, Money::EUR(10)));
            self::fail('The failure of the handler should be rethrown.');
        } catch (RuntimeException) {
            self::assertFalse($store->inTransaction());
        }

        self::assertSame(1, $store->streamVersion($stream));
    }

    public function testAConflictingCommandIsRetried(): void
    {
        $attempts = 0;
        $handlers = new HandlerMap()->on(OpenAccount::class, static function () use (&$attempts): void {
            if (++$attempts < 3) {
                throw ConcurrencyConflict::versionMismatch(StreamName::for('account', 'a'), ExpectedVersion::exactly(1), 2);
            }
        });

        new DefaultCommandBus($handlers, new EnvelopeFactory(), new RetryOnConflictMiddleware(3))
            ->dispatch(new OpenAccount(AccountId::generate(), 'Ada'));

        self::assertSame(3, $attempts);
    }

    public function testRetriesAreBounded(): void
    {
        $attempts = 0;
        $handlers = new HandlerMap()->on(OpenAccount::class, static function () use (&$attempts): never {
            $attempts++;

            throw ConcurrencyConflict::versionMismatch(StreamName::for('account', 'a'), ExpectedVersion::noStream(), 1);
        });
        $bus = new DefaultCommandBus($handlers, new EnvelopeFactory(), new RetryOnConflictMiddleware(2));

        try {
            $bus->dispatch(new OpenAccount(AccountId::generate(), 'Ada'));
            self::fail('The conflict should be rethrown once the attempts are exhausted.');
        } catch (ConcurrencyConflict $conflict) {
            self::assertStringContainsString('expected no stream', $conflict->getMessage());
        }

        self::assertSame(2, $attempts);
    }

    public function testACommandNestedInATransactionIsNotRetried(): void
    {
        $store = new InMemoryEventStore();
        $attempts = 0;
        $handlers = new HandlerMap()->on(OpenAccount::class, static function () use (&$attempts): never {
            $attempts++;

            throw ConcurrencyConflict::versionMismatch(StreamName::for('account', 'a'), ExpectedVersion::any(), 1);
        });
        $bus = new DefaultCommandBus($handlers, new EnvelopeFactory(), new RetryOnConflictMiddleware(5, $store));

        try {
            $store->transactional(static fn() => $bus->dispatch(new OpenAccount(AccountId::generate(), 'Ada')));
        } catch (ConcurrencyConflict) {
        }

        self::assertSame(1, $attempts);
    }

    public function testTheNumberOfAttemptsMustBePositive(): void
    {
        $this->expectException(InvalidArgument::class);

        new RetryOnConflictMiddleware(0);
    }

    public function testCorrelationAndCausationFlowFromACommandToWhatItProduces(): void
    {
        $causation = new CausationMiddleware();
        $envelopes = new EnvelopeFactory(new MessageTypeMap(), new SystemClock(), $causation);
        $produced = [];
        $handlers = new HandlerMap();
        $bus = new DefaultCommandBus($handlers, $envelopes, $causation);
        $handlers
            ->on(OpenAccount::class, static function (OpenAccount $command, Envelope $envelope) use (&$produced, $bus, $envelopes): void {
                $produced['command'] = $envelope;
                $produced['event'] = $envelopes->wrap(new AccountOpened($command->accountId, $command->owner));
                $bus->dispatch(new DepositMoney($command->accountId, Money::EUR(5)));
            })
            ->on(DepositMoney::class, static function (DepositMoney $command, Envelope $envelope) use (&$produced, $envelopes): void {
                $produced['nested'] = $envelope;
                $produced['nested event'] = $envelopes->wrap(new stdClass());
            });

        $bus->dispatch(new OpenAccount(AccountId::generate(), 'Ada'));

        $command = $produced['command'];
        $correlation = $command->id->value;

        self::assertSame($correlation, $command->metadata->get(Metadata::CORRELATION_ID));
        self::assertFalse($command->metadata->has(Metadata::CAUSATION_ID));

        foreach (['event', 'nested'] as $key) {
            self::assertSame($correlation, $produced[$key]->metadata->get(Metadata::CORRELATION_ID));
            self::assertSame($command->id->value, $produced[$key]->metadata->get(Metadata::CAUSATION_ID));
            self::assertSame(OpenAccount::class, $produced[$key]->metadata->get(Metadata::CAUSATION_NAME));
        }

        self::assertSame($correlation, $produced['nested event']->metadata->get(Metadata::CORRELATION_ID));
        self::assertSame($produced['nested']->id->value, $produced['nested event']->metadata->get(Metadata::CAUSATION_ID));

        // Outside of any handling, nothing is added.
        self::assertSame([], $envelopes->wrap(new stdClass())->metadata->values);
    }

    public function testSelectedMetadataIsPropagatedToWhatAMessageCauses(): void
    {
        $causation = new CausationMiddleware('actor', 'tenant');
        $envelopes = new EnvelopeFactory(new MessageTypeMap(), new SystemClock(), $causation);
        $produced = [];
        $handlers = new HandlerMap()->on(OpenAccount::class, static function () use (&$produced, $envelopes): void {
            $produced[] = $envelopes->wrap(new stdClass());
            $produced[] = $envelopes->wrap(new stdClass(), new Metadata(['tenant' => 'override']));
        });

        new DefaultCommandBus($handlers, $envelopes, $causation)->dispatch(
            new OpenAccount(AccountId::generate(), 'Ada'),
            new Metadata(['actor' => 'ada', 'tenant' => 'acme', 'ip' => '10.0.0.1']),
        );

        self::assertSame('ada', $produced[0]->metadata->get('actor'));
        self::assertSame('acme', $produced[0]->metadata->get('tenant'));
        self::assertFalse($produced[0]->metadata->has('ip'));
        self::assertSame('override', $produced[1]->metadata->get('tenant'));
    }

    public function testAnExistingCorrelationIdentifierIsKept(): void
    {
        $causation = new CausationMiddleware();
        $seen = null;
        $handlers = new HandlerMap()->on(OpenAccount::class, static function (OpenAccount $c, Envelope $envelope) use (&$seen): void {
            $seen = $envelope->metadata->get(Metadata::CORRELATION_ID);
        });

        new DefaultCommandBus($handlers, new EnvelopeFactory(), $causation)->dispatch(
            new OpenAccount(AccountId::generate(), 'Ada'),
            new Metadata([Metadata::CORRELATION_ID => 'trace-1']),
        );

        self::assertSame('trace-1', $seen);
    }

    public function testUnauthorizedMessagesNeverReachTheirHandler(): void
    {
        $handled = 0;
        $authorizer = new class implements Authorizer {
            public function isGranted(Envelope $envelope): bool
            {
                return $envelope->metadata->get('role') === 'teller';
            }
        };
        $bus = new DefaultCommandBus(
            new HandlerMap()->on(OpenAccount::class, static function () use (&$handled): void {
                $handled++;
            }),
            new EnvelopeFactory(),
            new AuthorizationMiddleware($authorizer),
        );
        $command = new OpenAccount(AccountId::generate(), 'Ada');

        $bus->dispatch($command, new Metadata(['role' => 'teller']));

        try {
            $bus->dispatch($command, new Metadata(['role' => 'guest']));
            self::fail('The command should have been refused.');
        } catch (AccessDenied $exception) {
            self::assertStringContainsString(OpenAccount::class, $exception->getMessage());
        }

        self::assertSame(1, $handled);
    }

    public function testCommandsDispatchedWhileHandlingAreQueued(): void
    {
        $trace = [];
        $handlers = new HandlerMap();
        $bus = new DefaultCommandBus($handlers, new EnvelopeFactory(), new SequentialDispatchMiddleware());
        $handlers
            ->on(OpenAccount::class, static function (OpenAccount $command) use (&$trace, $bus): void {
                $trace[] = 'open start';
                $bus->dispatch(new DepositMoney($command->accountId, Money::EUR(1)));
                $bus->dispatch(new DepositMoney($command->accountId, Money::EUR(2)));
                $trace[] = 'open end';
            })
            ->on(DepositMoney::class, static function (DepositMoney $command) use (&$trace): void {
                $trace[] = 'deposit ' . $command->money->getAmount();
            });

        $bus->dispatch(new OpenAccount(AccountId::generate(), 'Ada'));

        self::assertSame(['open start', 'open end', 'deposit 1', 'deposit 2'], $trace);
    }

    public function testQueuedCommandsAreDroppedWhenTheCurrentOneFails(): void
    {
        $deposits = 0;
        $handlers = new HandlerMap();
        $bus = new DefaultCommandBus($handlers, new EnvelopeFactory(), new SequentialDispatchMiddleware());
        $handlers
            ->on(OpenAccount::class, static function (OpenAccount $command) use ($bus): never {
                $bus->dispatch(new DepositMoney($command->accountId, Money::EUR(1)));

                throw new RuntimeException('boom');
            })
            ->on(DepositMoney::class, static function () use (&$deposits): void {
                $deposits++;
            });
        $id = AccountId::generate();

        try {
            $bus->dispatch(new OpenAccount($id, 'Ada'));
        } catch (RuntimeException) {
        }

        $bus->dispatch(new DepositMoney($id, Money::EUR(3)));

        self::assertSame(1, $deposits);
    }

    public function testAsyncMessagesAreProducedThenHandledWhenConsumed(): void
    {
        $producer = new class implements MessageProducer {
            /** @var list<Envelope> */
            public array $produced = [];

            public function produce(Envelope $envelope): void
            {
                $this->produced[] = $envelope;
            }
        };
        $handled = [];
        $handlers = new HandlerMap()
            ->on(SendWelcomeLetter::class, static function (SendWelcomeLetter $command) use (&$handled): void {
                $handled[] = $command::class;
            })
            ->on(OpenAccount::class, static function (OpenAccount $command) use (&$handled): void {
                $handled[] = $command::class;
            });
        $bus = new DefaultCommandBus($handlers, new EnvelopeFactory(), new AsyncMiddleware($producer));
        $id = AccountId::generate();

        $bus->dispatch(new SendWelcomeLetter($id));
        $bus->dispatch(new OpenAccount($id, 'Ada'));

        self::assertSame([OpenAccount::class], $handled);
        self::assertCount(1, $producer->produced);
        self::assertTrue($producer->produced[0]->metadata->get(Metadata::HANDLED_ASYNC));

        // The consumer dispatches the envelope it received: this time it is handled.
        $bus->dispatch($producer->produced[0]);

        self::assertSame([OpenAccount::class, SendWelcomeLetter::class], $handled);
        self::assertCount(1, $producer->produced);
    }
}
