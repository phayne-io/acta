<?php

declare(strict_types=1);

namespace Acta\Webhook\Signature;

/**
 * How the bytes of a signature are written in a header.
 */
enum HmacEncoding
{
    case Hex;
    case Base64;

    public function encode(string $bytes): string
    {
        return match ($this) {
            self::Hex => bin2hex($bytes),
            self::Base64 => base64_encode($bytes),
        };
    }
}
