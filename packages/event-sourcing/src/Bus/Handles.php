<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus;

use Attribute;

/**
 * Marks a method, or an invokable class, as a message handler.
 *
 * The handled message is the type of the first parameter unless given explicitly:
 *
 * ```php
 * final readonly class OpenAccountHandler
 * {
 *     #[Handles]
 *     public function __invoke(OpenAccount $command): void { ... }
 * }
 * ```
 *
 * A handler receives the message and, as an optional second argument, its envelope.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Handles
{
    /**
     * @param class-string|null $message message class, inferred from the first parameter when `null`
     */
    public function __construct(
        public ?string $message = null,
    ) {}
}
