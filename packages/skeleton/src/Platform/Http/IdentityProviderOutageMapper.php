<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Acta\Http\Exception\HttpException;
use Acta\Http\Problem\ExceptionMapper;
use Acta\Oidc\Exception\ProviderUnreachable;
use Override;
use Throwable;

/**
 * Reports an outage of the identity provider as what it is: a 503, not a refusal.
 *
 * A client that receives a 401 throws its token away; one that receives a 503 retries.
 */
final readonly class IdentityProviderOutageMapper implements ExceptionMapper
{
    #[Override]
    public function map(Throwable $exception): ?HttpException
    {
        return $exception instanceof ProviderUnreachable
            ? new HttpException(503, 'The identity provider cannot be reached. Retry later.', ['Retry-After' => '5'], previous: $exception)
            : null;
    }
}
