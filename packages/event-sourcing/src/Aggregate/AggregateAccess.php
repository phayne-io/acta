<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Aggregate;

use Acta\EventSourcing\Exception\InvalidArgument;
use Acta\EventSourcing\Message\DomainEvent;
use LogicException;
use Override;
use ReflectionClass;

/**
 * Gives the infrastructure access to the protected lifecycle of an aggregate.
 *
 * PHP lets a class call protected methods declared by an ancestor on any instance of
 * that ancestor. Extending {@see AggregateRoot} is therefore enough to reach them
 * without reflection and without widening the public API of aggregates.
 *
 * @internal
 */
final class AggregateAccess extends AggregateRoot
{
    private function __construct() {}

    /**
     * Creates an empty aggregate, bypassing its constructor, ready to replay its history.
     *
     * @template T of AggregateRoot
     *
     * @param class-string<T> $aggregateClass
     *
     * @return T
     */
    public static function instantiate(string $aggregateClass): AggregateRoot
    {
        if (!is_subclass_of($aggregateClass, AggregateRoot::class)) {
            throw new InvalidArgument(sprintf('"%s" is not an aggregate root.', $aggregateClass));
        }

        return new ReflectionClass($aggregateClass)->newInstanceWithoutConstructor();
    }

    /**
     * @return list<DomainEvent>
     */
    public static function releaseEvents(AggregateRoot $aggregate): array
    {
        return $aggregate->popRecordedEvents();
    }

    public static function replayOn(AggregateRoot $aggregate, DomainEvent $event, int $version): void
    {
        $aggregate->replay($event, $version);
    }

    #[Override]
    public function aggregateId(): never
    {
        throw new LogicException('AggregateAccess is not an aggregate.');
    }

    #[Override]
    protected function apply(DomainEvent $event): never
    {
        throw new LogicException('AggregateAccess is not an aggregate.');
    }
}
