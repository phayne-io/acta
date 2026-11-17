<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Message;

use Acta\EventSourcing\Exception\InvalidArgument;
use Acta\EventSourcing\Exception\UnknownMessage;
use ReflectionClass;

/**
 * Two-way map between message classes and their public names.
 *
 * Classes carrying a {@see MessageName} attribute must be registered so that their name
 * can be resolved back to a class when reading stored data. Classes without the
 * attribute need no registration: their name is their fully qualified class name.
 */
final class MessageTypeMap
{
    /** @var array<string, class-string> */
    private array $classes = [];

    /** @var array<class-string, array{name: string, version: int}> */
    private array $names = [];

    /**
     * @param class-string ...$classes message classes to register up front
     */
    public function __construct(string ...$classes)
    {
        foreach ($classes as $class) {
            $this->register($class);
        }
    }

    /**
     * Registers a message class, reading its {@see MessageName} attribute if present.
     *
     * @param class-string $class
     *
     * @throws InvalidArgument when the class does not exist or its name is already taken
     */
    public function register(string $class): void
    {
        if (isset($this->names[$class])) {
            return;
        }

        if (!class_exists($class)) {
            throw new InvalidArgument(sprintf('Message class "%s" does not exist.', $class));
        }

        $attribute = array_first(new ReflectionClass($class)->getAttributes(MessageName::class))?->newInstance();
        $name = $attribute->name ?? $class;

        if (isset($this->classes[$name]) && $this->classes[$name] !== $class) {
            throw new InvalidArgument(sprintf(
                'Message name "%s" is used by both "%s" and "%s".',
                $name,
                $this->classes[$name],
                $class,
            ));
        }

        $this->classes[$name] = $class;
        $this->names[$class] = ['name' => $name, 'version' => $attribute->version ?? 1];
    }

    /**
     * Returns the public name of a message.
     */
    public function nameOf(object $message): string
    {
        $this->register($message::class);

        return $this->names[$message::class]['name'];
    }

    /**
     * Returns the current schema version of a message.
     */
    public function versionOf(object $message): int
    {
        $this->register($message::class);

        return $this->names[$message::class]['version'];
    }

    /**
     * Resolves a public name back to its class.
     *
     * @return class-string
     *
     * @throws UnknownMessage when the name is neither registered nor an existing class
     */
    public function classOf(string $name): string
    {
        if (isset($this->classes[$name])) {
            return $this->classes[$name];
        }

        // Messages stored before their class received a #[MessageName] are still named after it.
        if (class_exists($name)) {
            return $name;
        }

        throw new UnknownMessage(sprintf(
            'No message class is registered under the name "%s". Register it in the MessageTypeMap.',
            $name,
        ));
    }
}
