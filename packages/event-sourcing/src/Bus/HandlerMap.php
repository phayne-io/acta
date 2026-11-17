<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Bus;

use Acta\EventSourcing\Exception\InvalidArgument;
use Acta\EventSourcing\Message\Envelope;
use Closure;
use Override;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Maps message classes to their handlers.
 *
 * Handlers can be registered in three ways:
 *
 * - {@see on()} binds any callable to a message class;
 * - {@see register()} reads the {@see Handles} attributes of handler objects;
 * - {@see registerLazy()} does the same from class names and only fetches the handler
 *   from the PSR-11 container when a message actually reaches it.
 *
 * A handler bound to an interface or a parent class also receives the messages that
 * implement or extend it, which is convenient for cross-cutting event listeners.
 */
final class HandlerMap implements HandlerLocator
{
    /** @var array<string, list<Closure>> */
    private array $handlers = [];

    /** @var array<class-string, list<Closure>> */
    private array $resolved = [];

    /**
     * @param ContainerInterface|null $container required by {@see registerLazy()} only
     */
    public function __construct(
        private readonly ?ContainerInterface $container = null,
    ) {}

    /**
     * Binds a callable to a message class.
     *
     * @param class-string                     $messageClass
     * @param callable(never, Envelope): mixed $handler      called with the message and its envelope; it may
     *                                                       declare the message only, with its precise type
     */
    public function on(string $messageClass, callable $handler): self
    {
        $this->handlers[$messageClass][] = $handler(...);
        $this->resolved = [];

        return $this;
    }

    /**
     * Registers handler objects, using their {@see Handles} attributes.
     *
     * @throws InvalidArgument when an object declares no handler
     */
    public function register(object ...$handlers): self
    {
        foreach ($handlers as $handler) {
            foreach (self::handledMessages($handler::class) as [$messageClass, $method]) {
                $this->on($messageClass, $handler->$method(...));
            }
        }

        return $this;
    }

    /**
     * Registers handler classes resolved from the container on first use.
     *
     * The class name is used as the service identifier.
     *
     * @param class-string ...$handlerClasses
     *
     * @throws InvalidArgument when no container was given or a class declares no handler
     */
    public function registerLazy(string ...$handlerClasses): self
    {
        $container = $this->container
            ?? throw new InvalidArgument('A PSR-11 container is required to register lazy handlers.');

        foreach ($handlerClasses as $handlerClass) {
            foreach (self::handledMessages($handlerClass) as [$messageClass, $method]) {
                $this->on(
                    $messageClass,
                    static fn(object $message, Envelope $envelope): mixed
                        => $container->get($handlerClass)->$method($message, $envelope),
                );
            }
        }

        return $this;
    }

    #[Override]
    public function handlersFor(object $message): iterable
    {
        return $this->resolved[$message::class] ??= $this->resolve($message::class);
    }

    /**
     * @param class-string $messageClass
     *
     * @return list<Closure>
     */
    private function resolve(string $messageClass): array
    {
        $types = [
            $messageClass,
            ...array_values(class_parents($messageClass) ?: []),
            ...array_values(class_implements($messageClass) ?: []),
        ];

        $handlers = [];

        foreach ($types as $type) {
            array_push($handlers, ...$this->handlers[$type] ?? []);
        }

        return $handlers;
    }

    /**
     * @param class-string $handlerClass
     *
     * @return non-empty-list<array{class-string, string}> pairs of message class and method name
     */
    private static function handledMessages(string $handlerClass): array
    {
        if (!class_exists($handlerClass)) {
            throw new InvalidArgument(sprintf('Handler class "%s" does not exist.', $handlerClass));
        }

        $reflection = new ReflectionClass($handlerClass);
        $found = [];

        foreach ($reflection->getAttributes(Handles::class) as $attribute) {
            if (!$reflection->hasMethod('__invoke')) {
                throw new InvalidArgument(sprintf(
                    '"%s" carries #[Handles] at class level but has no __invoke() method.',
                    $handlerClass,
                ));
            }

            array_push($found, ...self::messagesOf($reflection->getMethod('__invoke'), $attribute->newInstance()));
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Handles::class) as $attribute) {
                array_push($found, ...self::messagesOf($method, $attribute->newInstance()));
            }
        }

        if ($found === []) {
            throw new InvalidArgument(sprintf('"%s" declares no #[Handles] attribute.', $handlerClass));
        }

        return $found;
    }

    /**
     * @return non-empty-list<array{class-string, string}>
     */
    private static function messagesOf(ReflectionMethod $method, Handles $attribute): array
    {
        if ($attribute->message !== null) {
            return [[$attribute->message, $method->getName()]];
        }

        $type = array_first($method->getParameters())?->getType();
        $types = match (true) {
            $type instanceof ReflectionNamedType => [$type],
            $type instanceof ReflectionUnionType => $type->getTypes(),
            default => [],
        };

        $messages = [];

        foreach ($types as $candidate) {
            if ($candidate instanceof ReflectionNamedType && !$candidate->isBuiltin()) {
                /** @var class-string $messageClass */
                $messageClass = $candidate->getName();
                $messages[] = [$messageClass, $method->getName()];
            }
        }

        if ($messages === []) {
            throw new InvalidArgument(sprintf(
                'Cannot infer the message handled by %s::%s(): type its first parameter with a message class '
                . 'or pass the class to #[Handles].',
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ));
        }

        return $messages;
    }
}
