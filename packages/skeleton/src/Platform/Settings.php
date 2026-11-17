<?php

declare(strict_types=1);

namespace App\Platform;

use LogicException;
use Psr\Container\ContainerInterface;

/**
 * Typed access to the merged configuration.
 *
 * Values are addressed by a dotted path, `acta.http.contract` for instance. A value of
 * the wrong type is a configuration defect and is reported as such, with its path.
 */
final readonly class Settings
{
    /**
     * @param array<array-key, mixed> $config the merged configuration, as returned by `config/config.php`
     */
    public function __construct(
        private array $config,
    ) {}

    /**
     * Returns the settings of a container, where the configuration is the `config` service.
     */
    public static function of(ContainerInterface $container): self
    {
        $settings = $container->get(self::class);

        return $settings instanceof self ? $settings : throw new LogicException('The settings are not registered in the container.');
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->config;

        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }

            $value = $value[$key];
        }

        return $value;
    }

    public function string(string $path, string $default = ''): string
    {
        $value = $this->get($path, $default);

        return is_string($value) ? $value : throw self::invalid($path, 'a string');
    }

    public function int(string $path, int $default = 0): int
    {
        $value = $this->get($path, $default);

        return is_int($value) ? $value : throw self::invalid($path, 'an integer');
    }

    public function bool(string $path, bool $default = false): bool
    {
        $value = $this->get($path, $default);

        return is_bool($value) ? $value : throw self::invalid($path, 'a boolean');
    }

    /**
     * Returns a path of the project as an absolute path; a relative one is taken from
     * the root of the project.
     */
    public function path(string $path, string $default = ''): string
    {
        $value = $this->string($path, $default);

        return $value === '' || str_starts_with($value, '/') || str_contains($value, '://')
            ? $value
            : rtrim($this->string('acta.root'), '/') . '/' . $value;
    }

    /**
     * Returns a list or a map of strings, with its keys.
     *
     * @return array<array-key, string>
     */
    public function strings(string $path): array
    {
        $values = $this->get($path, []);
        $strings = [];

        foreach (is_array($values) ? $values : throw self::invalid($path, 'a list of strings') as $key => $value) {
            $strings[$key] = is_string($value) ? $value : throw self::invalid($path, 'a list of strings');
        }

        return $strings;
    }

    /**
     * Returns a list of class names, which is how contexts declare their messages and
     * their handlers.
     *
     * @return list<class-string>
     */
    public function classes(string $path): array
    {
        $classes = [];

        foreach (array_unique($this->strings($path)) as $class) {
            $classes[] = class_exists($class) || interface_exists($class)
                ? $class
                : throw new LogicException(sprintf('The configuration "%s" names the class "%s", which does not exist.', $path, $class));
        }

        return $classes;
    }

    /**
     * Returns a list of names of classes of a given type.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return list<class-string<T>>
     */
    public function implementations(string $path, string $type): array
    {
        $classes = [];

        foreach ($this->classes($path) as $class) {
            $classes[] = is_a($class, $type, true)
                ? $class
                : throw new LogicException(sprintf('The configuration "%s" names the class "%s", which is not a %s.', $path, $class, $type));
        }

        return $classes;
    }

    /**
     * Returns a list of maps, such as the webhook subscriptions.
     *
     * @return list<array<array-key, mixed>>
     */
    public function records(string $path): array
    {
        $values = $this->get($path, []);
        $records = [];

        foreach (is_array($values) ? $values : throw self::invalid($path, 'a list of maps') as $value) {
            $records[] = is_array($value) ? $value : throw self::invalid($path, 'a list of maps');
        }

        return $records;
    }

    private static function invalid(string $path, string $expected): LogicException
    {
        return new LogicException(sprintf('The configuration "%s" must be %s.', $path, $expected));
    }
}
