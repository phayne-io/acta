<?php

declare(strict_types=1);

namespace App\Banking\Domain;

use Acta\EventSourcing\Aggregate\AggregateId;

/**
 * Identifies a bank account.
 */
final readonly class AccountId extends AggregateId {}
