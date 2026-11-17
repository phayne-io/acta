<?php

declare(strict_types=1);

namespace Acta\Http\OpenApi;

/**
 * Where a parameter is carried in a request.
 */
enum ParameterLocation: string
{
    case Path = 'path';
    case Query = 'query';
    case Header = 'header';
    case Cookie = 'cookie';

    /**
     * Serialization style used when the contract gives none.
     */
    public function defaultStyle(): string
    {
        return match ($this) {
            self::Query, self::Cookie => 'form',
            self::Path, self::Header => 'simple',
        };
    }

    /**
     * Serialization styles this package can decode.
     *
     * @return list<string>
     */
    public function supportedStyles(): array
    {
        return match ($this) {
            self::Query => ['form', 'spaceDelimited', 'pipeDelimited', 'deepObject'],
            self::Cookie => ['form'],
            self::Path, self::Header => ['simple'],
        };
    }
}
