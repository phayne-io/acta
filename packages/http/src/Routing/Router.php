<?php

declare(strict_types=1);

namespace Acta\Http\Routing;

use Acta\Http\Exception\HttpException;
use Acta\Http\OpenApi\Contract;
use Acta\Http\OpenApi\Operation;

/**
 * Finds the operation of the contract that a method and a path designate.
 *
 * The routes are the `paths` of the contract, nothing is declared twice. As OpenAPI
 * requires, a concrete path wins over a templated one: `/accounts/search` is matched
 * before `/accounts/{accountId}`.
 */
final class Router
{
    /** @var array<string, array<string, Operation>> path => method => operation */
    private array $static = [];

    /** @var list<array{string, list<string>, array<string, Operation>}> pattern, variable names, then method => operation */
    private array $dynamic = [];

    /**
     * @param string $basePath prefix the API is served under, such as `/api/v1`; it is
     *                         removed from the request path before matching
     */
    public function __construct(
        Contract $contract,
        private readonly string $basePath = '',
    ) {
        $templates = [];

        foreach ($contract->operations as $operation) {
            if (str_contains($operation->path, '{')) {
                $templates[$operation->path][$operation->method] = $operation;
            } else {
                $this->static[$operation->path][$operation->method] = $operation;
            }
        }

        // Fewer variables first, then the longest template: the most specific wins.
        uksort($templates, static fn(string $a, string $b): int => [substr_count($a, '{'), strlen($b)] <=> [substr_count($b, '{'), strlen($a)]);

        foreach ($templates as $template => $operations) {
            $this->dynamic[] = [...self::pattern($template), $operations];
        }
    }

    /**
     * @param string $path the path of the request URI, still percent-encoded
     *
     * @throws HttpException 404 when no path matches, 405 when the path exists for other methods
     */
    public function match(string $method, string $path): RouteMatch
    {
        if ($this->basePath !== '') {
            if (!str_starts_with($path, $this->basePath)) {
                throw HttpException::notFound();
            }

            $path = substr($path, strlen($this->basePath)) ?: '/';
        }

        $method = strtoupper($method);
        $allowed = [];

        if (isset($this->static[$path])) {
            if (isset($this->static[$path][$method])) {
                return new RouteMatch($this->static[$path][$method]);
            }

            $allowed = array_keys($this->static[$path]);
        }

        foreach ($this->dynamic as [$pattern, $names, $operations]) {
            if (preg_match($pattern, $path, $matches) !== 1) {
                continue;
            }

            if (isset($operations[$method])) {
                return new RouteMatch(
                    $operations[$method],
                    array_combine($names, array_map(rawurldecode(...), array_slice($matches, 1))),
                );
            }

            $allowed = [...$allowed, ...array_keys($operations)];
        }

        throw $allowed === []
            ? HttpException::notFound()
            : HttpException::methodNotAllowed(array_values(array_unique($allowed)));
    }

    /**
     * Turns `/accounts/{accountId}` into a regular expression with one group per variable.
     *
     * @return array{string, list<string>} the pattern and the names of its variables, in order
     */
    private static function pattern(string $template): array
    {
        $pattern = '';
        $names = [];

        foreach (preg_split('/\{([^{}\/]+)\}/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $position => $part) {
            if ($position % 2 === 0) {
                $pattern .= preg_quote($part, '~');
            } else {
                $pattern .= '([^/]+)';
                $names[] = $part;
            }
        }

        return ['~^' . $pattern . '$~', $names];
    }
}
