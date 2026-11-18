<?php

declare(strict_types=1);

namespace Acta\Webhook\Tests\Unit;

use Acta\Webhook\Incoming\DeliveryLog;
use Acta\Webhook\Incoming\InMemoryDeliveryLog;
use Acta\Webhook\Testing\DeliveryLogContract;
use Override;

final class InMemoryDeliveryLogTest extends DeliveryLogContract
{
    #[Override]
    protected function createLog(): DeliveryLog
    {
        return new InMemoryDeliveryLog();
    }
}
