<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Serializer;

use Attribute;

/**
 * Declares the class of the items of an array constructor parameter.
 *
 * PHP has no typed arrays, so {@see ReflectionPayloadSerializer} needs this hint to
 * rebuild the objects of a list:
 *
 * ```php
 * public function __construct(
 *     #[ListOf(OrderLine::class)] public array $lines,
 * ) {}
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class ListOf
{
    /**
     * @param class-string $class
     */
    public function __construct(
        public string $class,
    ) {}
}
