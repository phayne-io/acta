<?php

declare(strict_types=1);

namespace Acta\Webhook\Outgoing;

/**
 * What a run of the sender did.
 */
final readonly class DeliveryReport
{
    /**
     * @param int $delivered deliveries acknowledged by their subscriber
     * @param int $retried   deliveries that failed and will be attempted again
     * @param int $dead      deliveries given up
     */
    public function __construct(
        public int $delivered = 0,
        public int $retried = 0,
        public int $dead = 0,
    ) {}

    public function total(): int
    {
        return $this->delivered + $this->retried + $this->dead;
    }
}
