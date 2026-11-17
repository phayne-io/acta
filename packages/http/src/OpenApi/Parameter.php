<?php

declare(strict_types=1);

namespace Acta\Http\OpenApi;

/**
 * Parameter of an operation.
 */
final readonly class Parameter
{
    /**
     * @param string      $name          name of the parameter, case-sensitive except for headers
     * @param bool        $required      whether a request without it is rejected
     * @param string      $style         serialization style: `simple`, `form`, `spaceDelimited`, `pipeDelimited` or `deepObject`
     * @param bool        $explode       whether arrays and objects use one entry per value
     * @param string|null $schemaPointer JSON pointer to the schema of the value, when the contract gives one
     * @param string|null $mediaType     media type of the value for parameters described by `content`, such as `application/json`
     * @param string      $pointer       JSON pointer to the parameter in the contract
     */
    public function __construct(
        public string $name,
        public ParameterLocation $in,
        public bool $required,
        public string $style,
        public bool $explode,
        public ?string $schemaPointer,
        public ?string $mediaType,
        public string $pointer,
    ) {}
}
