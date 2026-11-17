<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Message;

use Acta\EventSourcing\Exception\InvalidArgument;
use Attribute;

/**
 * Gives a message a stable public name and a schema version.
 *
 * The name is what gets stored and sent over the wire, so a class can be renamed or
 * moved without touching existing data. Without this attribute the fully qualified
 * class name is used, as Prooph's `FQCNMessageFactory` did.
 *
 * ```php
 * #[MessageName('banking.account-opened', version: 2)]
 * final readonly class AccountOpened implements DomainEvent { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class MessageName
{
    /**
     * @param string $name    public name, unique across the application
     * @param int    $version schema version of the payload, starting at 1; raise it when the
     *                        shape changes and register an upcaster for the older versions
     */
    public function __construct(
        public string $name,
        public int $version = 1,
    ) {
        if ($name === '') {
            throw new InvalidArgument('A message name cannot be empty.');
        }

        if ($version < 1) {
            throw new InvalidArgument(sprintf('The schema version of "%s" must be at least 1.', $name));
        }
    }
}
