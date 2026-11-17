<?php

declare(strict_types=1);

namespace Acta\Http\Handler;

use Acta\Http\Exception\ContractError;
use Acta\Http\OpenApi\Contract;
use Closure;
use Override;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;

/**
 * Maps the operations of the contract to the request handlers implementing them.
 */
final class OperationHandlers
{
    /** @var array<string, RequestHandlerInterface|string> handler, or its identifier in the container */
    private array $handlers = [];

    /**
     * @param ContainerInterface|null $container resolves the handlers registered with {@see self::registerLazy()}
     */
    public function __construct(
        private readonly ?ContainerInterface $container = null,
    ) {}

    /**
     * Attaches a handler to an operation.
     *
     * @param RequestHandlerInterface|Closure(ServerRequestInterface): ResponseInterface $handler
     *
     * @throws ContractError when the operation already has a handler
     */
    public function on(string $operationId, RequestHandlerInterface|Closure $handler): static
    {
        $this->add($operationId, $handler instanceof Closure ? self::wrap($handler) : $handler);

        return $this;
    }

    /**
     * Attaches handlers to the operations their {@see Operation} attributes name.
     *
     * @throws ContractError when a handler has no attribute or an operation already has a handler
     */
    public function register(RequestHandlerInterface ...$handlers): static
    {
        foreach ($handlers as $handler) {
            foreach (self::operationsOf($handler::class) as $operationId) {
                $this->add($operationId, $handler);
            }
        }

        return $this;
    }

    /**
     * Attaches handler classes without creating them: each one is fetched from the
     * container, under its class name, the first time its operation is called.
     *
     * @param class-string<RequestHandlerInterface> ...$classes
     *
     * @throws ContractError when no container was given, a class has no attribute or an operation already has a handler
     */
    public function registerLazy(string ...$classes): static
    {
        if ($this->container === null) {
            throw new ContractError('Lazy handlers need a container: pass one to the constructor.');
        }

        foreach ($classes as $class) {
            foreach (self::operationsOf($class) as $operationId) {
                $this->add($operationId, $class);
            }
        }

        return $this;
    }

    public function has(string $operationId): bool
    {
        return isset($this->handlers[$operationId]);
    }

    /**
     * Returns the handler of an operation, or null when it has none.
     *
     * @throws ContractError when the container does not return a request handler
     */
    public function get(string $operationId): ?RequestHandlerInterface
    {
        $handler = $this->handlers[$operationId] ?? null;

        if (!is_string($handler)) {
            return $handler;
        }

        $resolved = $this->container?->get($handler);

        if (!$resolved instanceof RequestHandlerInterface) {
            throw new ContractError(sprintf('The container did not return a request handler for "%s".', $handler));
        }

        return $this->handlers[$operationId] = $resolved;
    }

    /**
     * Compares the handlers with a contract.
     *
     * Assert in a test that both lists are empty: every operation is then implemented
     * and no handler points to an operation that was renamed or removed.
     *
     * @return array{unhandled: list<string>, unknown: list<string>} operations without handler, and handled
     *                                                               identifiers the contract does not declare
     */
    public function compare(Contract $contract): array
    {
        return [
            'unhandled' => array_map(strval(...), array_values(array_diff(array_keys($contract->operations), array_keys($this->handlers)))),
            'unknown' => array_map(strval(...), array_values(array_diff(array_keys($this->handlers), array_keys($contract->operations)))),
        ];
    }

    private function add(string $operationId, RequestHandlerInterface|string $handler): void
    {
        if (isset($this->handlers[$operationId])) {
            throw new ContractError(sprintf('Operation "%s" already has a handler.', $operationId));
        }

        $this->handlers[$operationId] = $handler;
    }

    /**
     * @param class-string $class
     *
     * @return non-empty-list<string>
     */
    private static function operationsOf(string $class): array
    {
        $operations = array_map(
            static fn($attribute): string => $attribute->newInstance()->id,
            new ReflectionClass($class)->getAttributes(Operation::class),
        );

        return $operations !== []
            ? $operations
            : throw new ContractError(sprintf('%s has no #[Operation] attribute.', $class));
    }

    /**
     * @param Closure(ServerRequestInterface): ResponseInterface $handler
     */
    private static function wrap(Closure $handler): RequestHandlerInterface
    {
        return new readonly class ($handler) implements RequestHandlerInterface {
            /**
             * @param Closure(ServerRequestInterface): ResponseInterface $handler
             */
            public function __construct(private Closure $handler) {}

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->handler)($request);
            }
        };
    }
}
