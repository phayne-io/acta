<?php

declare(strict_types=1);

namespace Acta\Http\Handler;

use Attribute;

/**
 * Declares which operation of the contract a request handler implements.
 *
 *     #[Operation('openAccount')]
 *     final readonly class OpenAccountHandler implements RequestHandlerInterface
 *
 * Repeat the attribute on a handler that serves several operations.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Operation
{
    /**
     * @param string $id the `operationId` of the contract
     */
    public function __construct(
        public string $id,
    ) {}
}
