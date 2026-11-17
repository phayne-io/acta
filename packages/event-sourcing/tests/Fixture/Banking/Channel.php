<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Fixture\Banking;

enum Channel
{
    case Branch;
    case Online;
}
