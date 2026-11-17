<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus;

use Acta\EventSourcing\Exception\HandlerNotFound;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\Message\Query;
use NoDiscard;

/**
 * Primary port of the read side: asks a query to its single handler.
 *
 * Unlike Prooph's `QueryBus`, the answer is returned directly instead of being wrapped
 * in a promise.
 */
interface QueryBus
{
    /**
     * @param Query|Envelope $query    the query, or an envelope already carrying one
     * @param Metadata|null  $metadata context to attach to the query
     *
     * @return mixed whatever the handler returns, usually a read model or a DTO
     *
     * @throws HandlerNotFound when the query does not have exactly one handler
     */
    #[NoDiscard('A query is only useful for its answer.')]
    public function ask(Query|Envelope $query, ?Metadata $metadata = null): mixed;
}
