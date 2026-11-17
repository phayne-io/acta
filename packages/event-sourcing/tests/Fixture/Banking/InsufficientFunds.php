<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Fixture\Banking;

use DomainException;

final class InsufficientFunds extends DomainException {}
