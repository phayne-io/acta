<?php

declare(strict_types=1);

namespace Acta\Webhook\Tests\Unit;

use Acta\Webhook\Outgoing\DeliveryQueue;
use Acta\Webhook\Outgoing\InMemoryDeliveryQueue;
use Acta\Webhook\Testing\DeliveryQueueContract;
use Override;

final class InMemoryDeliveryQueueTest extends DeliveryQueueContract
{
    #[Override]
    protected function createQueue(): DeliveryQueue
    {
        return new InMemoryDeliveryQueue();
    }
}
