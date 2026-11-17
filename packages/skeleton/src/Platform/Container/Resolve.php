<?php

declare(strict_types=1);

namespace App\Platform\Container;

use LogicException;
use Psr\Container\ContainerInterface;

/**
 * Fetches services with their type, which PSR-11 does not carry.
 */
final class Resolve
{
    /**
     * Returns the service registered under a class or interface name.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    public static function from(ContainerInterface $container, string $type): object
    {
        return self::named($container, $type, $type);
    }

    /**
     * Returns the service registered under an identifier, checking what it is.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    public static function named(ContainerInterface $container, string $id, string $type): object
    {
        $service = $container->get($id);

        return $service instanceof $type
            ? $service
            : throw new LogicException(sprintf('The service "%s" is expected to be a %s, got %s.', $id, $type, get_debug_type($service)));
    }

    /**
     * Returns several services of the same type.
     *
     * @template T of object
     *
     * @param iterable<string> $ids
     * @param class-string<T>  $type
     *
     * @return list<T>
     */
    public static function all(ContainerInterface $container, iterable $ids, string $type): array
    {
        $services = [];

        foreach ($ids as $id) {
            $services[] = self::named($container, $id, $type);
        }

        return $services;
    }
}
