<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit\Bus;

use Acta\EventSourcing\Bus\DefaultCommandBus;
use Acta\EventSourcing\Bus\DefaultEventBus;
use Acta\EventSourcing\Bus\DefaultQueryBus;
use Acta\EventSourcing\Bus\HandlerMap;
use Acta\EventSourcing\Bus\Handles;
use Acta\EventSourcing\Bus\Middleware;
use Acta\EventSourcing\Exception\HandlerNotFound;
use Acta\EventSourcing\Exception\InvalidArgument;
use Acta\EventSourcing\Exception\ListenerFailures;
use Acta\EventSourcing\Message\Command;
use Acta\EventSourcing\Message\DomainEvent;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountId;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountOpened;
use Acta\EventSourcing\Tests\Fixture\Banking\DepositMoney;
use Acta\EventSourcing\Tests\Fixture\Banking\GetBalance;
use Acta\EventSourcing\Tests\Fixture\Banking\MoneyDeposited;
use Acta\EventSourcing\Tests\Fixture\Banking\OpenAccount;
use Acta\EventSourcing\Tests\Fixture\InvokableBalanceHandler;
use ArrayObject;
use Closure;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

final class BusTest extends TestCase
{
    public function testACommandReachesItsHandlerWithItsEnvelope(): void
    {
        $received = [];
        $bus = new DefaultCommandBus(new HandlerMap()->on(
            OpenAccount::class,
            static function (OpenAccount $command, Envelope $envelope) use (&$received): void {
                $received = [$command, $envelope];
            },
        ));
        $command = new OpenAccount(AccountId::generate(), 'Ada');

        $bus->dispatch($command, new Metadata(['actor' => 'ada']));

        self::assertSame($command, $received[0]);
        self::assertSame(OpenAccount::class, $received[1]->name);
        self::assertSame('ada', $received[1]->metadata->get('actor'));
    }

    public function testACommandNeedsExactlyOneHandler(): void
    {
        $command = new OpenAccount(AccountId::generate(), 'Ada');
        $noop = static fn(): null => null;

        try {
            new DefaultCommandBus(new HandlerMap())->dispatch($command);
            self::fail('A command without handler should be refused.');
        } catch (HandlerNotFound $exception) {
            self::assertStringContainsString('No handler', $exception->getMessage());
        }

        $this->expectException(HandlerNotFound::class);
        $this->expectExceptionMessage('2 handlers');

        new DefaultCommandBus(new HandlerMap()->on(OpenAccount::class, $noop)->on(Command::class, $noop))->dispatch($command);
    }

    public function testAQueryReturnsTheAnswerOfItsHandler(): void
    {
        $bus = new DefaultQueryBus(new HandlerMap()->on(GetBalance::class, static fn(GetBalance $query): int => 42));

        self::assertSame(42, $bus->ask(new GetBalance(AccountId::generate())));
    }

    public function testAnEventReachesAllItsListenersAndMayHaveNone(): void
    {
        $calls = [];
        $listeners = new HandlerMap()
            ->on(AccountOpened::class, static function () use (&$calls): void {
                $calls[] = 'first';
            })
            ->on(AccountOpened::class, static function () use (&$calls): void {
                $calls[] = 'second';
            })
            ->on(DomainEvent::class, static function (DomainEvent $event) use (&$calls): void {
                $calls[] = $event::class;
            });
        $bus = new DefaultEventBus($listeners);
        $id = AccountId::generate();

        $bus->publish(new AccountOpened($id, 'Ada'), new stdClass());

        self::assertSame(['first', 'second', AccountOpened::class], $calls);
    }

    public function testTheEventBusStopsAtTheFirstFailureByDefault(): void
    {
        $calls = 0;
        $listeners = new HandlerMap()
            ->on(AccountOpened::class, static fn() => throw new RuntimeException('boom'))
            ->on(AccountOpened::class, static function () use (&$calls): void {
                $calls++;
            });

        try {
            new DefaultEventBus($listeners)->publish(new AccountOpened(AccountId::generate(), 'Ada'));
            self::fail('The failure of a listener should be rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertSame(0, $calls);
    }

    public function testTheEventBusCanCollectFailures(): void
    {
        $calls = 0;
        $listeners = new HandlerMap()
            ->on(AccountOpened::class, static fn() => throw new RuntimeException('first'))
            ->on(AccountOpened::class, static function () use (&$calls): void {
                $calls++;
            })
            ->on(AccountOpened::class, static fn() => throw new RuntimeException('third'));

        try {
            new DefaultEventBus($listeners, collectErrors: true)->publish(new AccountOpened(AccountId::generate(), 'Ada'));
            self::fail('Collected failures should be reported.');
        } catch (ListenerFailures $failures) {
            self::assertCount(2, $failures->failures);
            self::assertStringContainsString('first; third', $failures->getMessage());
            self::assertSame($failures->failures[0], $failures->getPrevious());
        }

        self::assertSame(1, $calls);
    }

    public function testMiddlewaresWrapTheHandlerOutermostFirst(): void
    {
        /** @var ArrayObject<int, string> $trace */
        $trace = new ArrayObject();
        $middleware = static fn(string $name): Middleware => new class ($name, $trace) implements Middleware {
            /**
             * @param ArrayObject<int, string> $trace
             */
            public function __construct(
                private readonly string $name,
                private readonly ArrayObject $trace,
            ) {}

            public function handle(Envelope $envelope, Closure $next): mixed
            {
                $this->trace->append($this->name . ' before');
                $result = $next($envelope->withAddedMetadata($this->name, true));
                $this->trace->append($this->name . ' after');

                return $result;
            }
        };

        $bus = new DefaultQueryBus(
            new HandlerMap()->on(GetBalance::class, static function (GetBalance $query, Envelope $envelope) use ($trace): array {
                $trace->append('handler');

                return array_keys($envelope->metadata->values);
            }),
            new EnvelopeFactory(),
            $middleware('outer'),
            $middleware('inner'),
        );

        self::assertSame(['outer', 'inner'], $bus->ask(new GetBalance(AccountId::generate())));
        self::assertSame(
            ['outer before', 'inner before', 'handler', 'inner after', 'outer after'],
            $trace->getArrayCopy(),
        );
    }

    public function testHandlersAreDiscoveredFromAttributes(): void
    {
        $handler = new class {
            /** @var list<string> */
            public array $handled = [];

            #[Handles]
            public function open(OpenAccount $command): void
            {
                $this->handled[] = 'open';
            }

            #[Handles]
            public function either(DepositMoney|MoneyDeposited $message): void
            {
                $this->handled[] = $message::class;
            }

            #[Handles(GetBalance::class)]
            public function balance(object $query): int
            {
                return 7;
            }

            public function ignored(OpenAccount $command): void
            {
                $this->handled[] = 'ignored';
            }
        };
        $handlers = new HandlerMap()->register($handler);
        $id = AccountId::generate();

        new DefaultCommandBus($handlers)->dispatch(new OpenAccount($id, 'Ada'));
        new DefaultCommandBus($handlers)->dispatch(new DepositMoney($id, Money::EUR(5)));

        self::assertSame(['open', DepositMoney::class], $handler->handled);
        self::assertSame(7, new DefaultQueryBus($handlers)->ask(new GetBalance($id)));
    }

    public function testLazyHandlersAreFetchedFromTheContainerOnFirstUse(): void
    {
        $container = new class implements ContainerInterface {
            /** @var list<string> */
            public array $fetched = [];

            public function get(string $id): object
            {
                $this->fetched[] = $id;

                return new $id();
            }

            public function has(string $id): bool
            {
                return class_exists($id);
            }
        };
        $handlers = new HandlerMap($container)->registerLazy(InvokableBalanceHandler::class);

        self::assertSame([], $container->fetched);
        self::assertSame(99, new DefaultQueryBus($handlers)->ask(new GetBalance(AccountId::generate())));
        self::assertSame([InvokableBalanceHandler::class], $container->fetched);
    }

    public function testHandlerRegistrationIsValidated(): void
    {
        $failures = 0;
        $attempts = [
            static fn() => new HandlerMap()->registerLazy(InvokableBalanceHandler::class),
            static fn() => new HandlerMap()->register(new stdClass()),
            static fn() => new HandlerMap()->register(new class {
                #[Handles]
                public function untyped(string $notAMessage): void {}
            }),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (InvalidArgument) {
                $failures++;
            }
        }

        self::assertSame(3, $failures);
    }
}
