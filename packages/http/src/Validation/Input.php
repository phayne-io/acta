<?php

declare(strict_types=1);

namespace Acta\Http\Validation;

use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What a request carries, decoded and validated against the contract.
 *
 * {@see RequestValidationMiddleware} stores it as a request attribute; handlers read it
 * with {@see self::from()} rather than parsing the request again. Parameters have the
 * types their schemas declare: an `integer` query parameter is an `int`.
 */
final readonly class Input
{
    /**
     * @param array<string, mixed> $path        path parameters, keyed by name
     * @param array<string, mixed> $query       declared query parameters that were sent or have a default
     * @param array<string, mixed> $headers     declared headers, keyed by the name the contract gives
     * @param array<string, mixed> $cookies     declared cookies
     * @param mixed                $body        decoded body: arrays for JSON and forms, a string otherwise, null when absent
     * @param string|null          $contentType media type of the body, without parameters
     */
    public function __construct(
        public array $path = [],
        public array $query = [],
        public array $headers = [],
        public array $cookies = [],
        public mixed $body = null,
        public ?string $contentType = null,
    ) {}

    /**
     * Returns the validated input of a request.
     *
     * @throws LogicException when the request did not go through {@see RequestValidationMiddleware}
     */
    public static function from(ServerRequestInterface $request): self
    {
        $input = $request->getAttribute(self::class);

        return $input instanceof self
            ? $input
            : throw new LogicException('This request was not validated: add RequestValidationMiddleware to the pipeline.');
    }

    /**
     * Returns the body when it is a JSON object or a form, an empty array otherwise.
     *
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        $fields = [];

        foreach (is_array($this->body) && !array_is_list($this->body) ? $this->body : [] as $name => $value) {
            $fields[(string) $name] = $value;
        }

        return $fields;
    }
}
