<?php

declare(strict_types=1);

namespace Acta\EventSourcing\EventStore;

use Acta\EventSourcing\Exception\InvalidArgument;
use Acta\EventSourcing\Message\Metadata;
use NoDiscard;

/**
 * Filters events on their metadata, as Prooph's `MetadataMatcher` did.
 *
 * Conditions are combined with a logical AND and apply to top-level metadata keys:
 *
 * ```php
 * $matcher = MetadataMatcher::where('tenant', Operator::Equals, 'acme')
 *     ->and('attempt', Operator::LowerThan, 3);
 * ```
 */
final readonly class MetadataMatcher
{
    /**
     * @param list<array{field: string, operator: Operator, value: mixed}> $conditions
     */
    private function __construct(
        public array $conditions,
    ) {}

    /**
     * @throws InvalidArgument when the value does not fit the operator
     */
    public static function where(string $field, Operator $operator, mixed $value): self
    {
        return new self([])->and($field, $operator, $value);
    }

    /**
     * Returns a matcher with one more condition.
     *
     * @throws InvalidArgument when the value does not fit the operator
     */
    #[NoDiscard]
    public function and(string $field, Operator $operator, mixed $value): self
    {
        if ($field === '') {
            throw new InvalidArgument('A metadata field name cannot be empty.');
        }

        $valid = match ($operator) {
            Operator::In, Operator::NotIn => is_array($value) && array_is_list($value),
            Operator::Regex => is_string($value),
            Operator::Equals, Operator::NotEquals => $value === null || is_scalar($value),
            default => is_int($value) || is_float($value) || is_string($value),
        };

        if (!$valid) {
            throw new InvalidArgument(sprintf(
                'A value of type "%s" cannot be used with the "%s" operator on metadata field "%s".',
                get_debug_type($value),
                $operator->value,
                $field,
            ));
        }

        return new self([...$this->conditions, ['field' => $field, 'operator' => $operator, 'value' => $value]]);
    }

    public function matches(Metadata $metadata): bool
    {
        return array_all(
            $this->conditions,
            static fn(array $condition): bool => $condition['operator']->test(
                $metadata->get($condition['field']),
                $condition['value'],
            ),
        );
    }
}
