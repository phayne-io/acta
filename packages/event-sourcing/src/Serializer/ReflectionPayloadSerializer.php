<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Serializer;

use Acta\EventSourcing\Exception\SerializationFailed;
use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Override;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Default payload serializer, driven by constructor signatures.
 *
 * A message is described by the parameters of its constructor, which is what promoted
 * constructor properties give for free. Each parameter is read from the property of the
 * same name and converted with these rules:
 *
 * - scalars, `null` and arrays are kept as they are;
 * - backed enums are stored by value, pure enums by case name;
 * - dates are stored as RFC 3339 strings with microseconds;
 * - objects implementing {@see SerializablePayload} use their own representation;
 * - `Stringable` objects with a static `fromString()` are stored as strings, which covers
 *   most identifiers and small value objects;
 * - any other object is stored as a nested map, following the same rules;
 * - arrays of objects need a {@see ListOf} attribute to be rebuilt.
 *
 * When rebuilding, a value that already is an object of the expected class is kept as
 * it is, which lets a caller mix decoded data with value objects it built itself.
 *
 * Constructor parameters missing from a stored payload fall back to their default
 * value, or to `null` when nullable, so adding an optional field needs no upcaster.
 */
final class ReflectionPayloadSerializer implements PayloadSerializer
{
    private const string DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    /** @var array<class-string, list<ReflectionParameter>> */
    private array $parameters = [];

    #[Override]
    public function serialize(object $message): array
    {
        return $message instanceof SerializablePayload
            ? $message->toPayload()
            : $this->normalizeObject($message);
    }

    #[Override]
    public function deserialize(string $class, array $payload): object
    {
        try {
            return is_subclass_of($class, SerializablePayload::class)
                ? $class::fromPayload($payload)
                : $this->denormalizeObject($class, $payload);
        } catch (SerializationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SerializationFailed(
                sprintf('Cannot rebuild "%s" from its payload: %s', $class, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeObject(object $object): array
    {
        $payload = [];

        foreach ($this->parametersOf($object::class) as $parameter) {
            $name = $parameter->getName();

            try {
                $value = new ReflectionProperty($object, $name)->getValue($object);
            } catch (ReflectionException $exception) {
                throw new SerializationFailed(
                    sprintf(
                        'Cannot serialize "%s": constructor parameter "$%s" has no property of the same name. '
                        . 'Promote the parameter or implement %s.',
                        $object::class,
                        $name,
                        SerializablePayload::class,
                    ),
                    previous: $exception,
                );
            }

            $payload[$name] = $this->normalize($value);
        }

        return $payload;
    }

    private function normalize(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            is_array($value) => array_map($this->normalize(...), $value),
            $value instanceof SerializablePayload => $value->toPayload(),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(self::DATE_FORMAT),
            $value instanceof Stringable && method_exists($value, 'fromString') => (string) $value,
            is_object($value) => $this->normalizeObject($value),
            default => throw new SerializationFailed(
                sprintf('Values of type "%s" cannot be serialized.', get_debug_type($value)),
            ),
        };
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $payload
     *
     * @return T
     */
    private function denormalizeObject(string $class, array $payload): object
    {
        $arguments = [];

        foreach ($this->parametersOf($class) as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $payload)) {
                $arguments[$name] = $this->denormalize($payload[$name], $parameter);

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                continue;
            }

            if (!$parameter->allowsNull()) {
                throw new SerializationFailed(
                    sprintf('Cannot rebuild "%s": field "%s" is missing from the payload.', $class, $name),
                );
            }

            $arguments[$name] = null;
        }

        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        if ($constructor->isPublic()) {
            return $reflection->newInstanceArgs($arguments);
        }

        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor->invokeArgs($instance, $arguments);

        return $instance;
    }

    private function denormalize(mixed $value, ReflectionParameter $parameter): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            if ($type === null || is_scalar($value)) {
                return $value;
            }

            throw new SerializationFailed(sprintf(
                'Parameter "$%s" has a composite type: implement %s on its class to control the conversion.',
                $parameter->getName(),
                SerializablePayload::class,
            ));
        }

        if (!$type->isBuiltin()) {
            return $this->denormalizeAs($type->getName(), $value);
        }

        if ($type->getName() === 'float' && is_int($value)) {
            return (float) $value;
        }

        if (is_array($value)) {
            $listOf = array_first($parameter->getAttributes(ListOf::class))?->newInstance();

            if ($listOf !== null) {
                return array_map(fn(mixed $item): mixed => $this->denormalizeAs($listOf->class, $item), $value);
            }
        }

        return $value;
    }

    private function denormalizeAs(string $type, mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            $value instanceof $type => $value,
            is_array($value) && is_subclass_of($type, SerializablePayload::class) => $type::fromPayload(self::map($value)),
            is_subclass_of($type, BackedEnum::class) && (is_int($value) || is_string($value)) => $type::from($value),
            is_subclass_of($type, UnitEnum::class) && is_string($value) => constant($type . '::' . $value),
            is_a($type, DateTimeInterface::class, true) && is_string($value) => $type === DateTime::class
                ? new DateTime($value)
                : new DateTimeImmutable($value),
            is_string($value) && method_exists($type, 'fromString') => $type::fromString($value),
            is_array($value) && class_exists($type) => $this->denormalizeObject($type, self::map($value)),
            default => throw new SerializationFailed(
                sprintf('Cannot convert a value of type "%s" to "%s".', get_debug_type($value), $type),
            ),
        };
    }

    /**
     * Returns a decoded JSON object as a map with string keys.
     *
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function map(array $value): array
    {
        $map = [];

        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * @param class-string $class
     *
     * @return list<ReflectionParameter>
     */
    private function parametersOf(string $class): array
    {
        return $this->parameters[$class]
            ??= new ReflectionClass($class)->getConstructor()?->getParameters() ?? [];
    }
}
