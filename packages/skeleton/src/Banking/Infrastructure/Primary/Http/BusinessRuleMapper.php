<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Primary\Http;

use Acta\Http\Exception\HttpException;
use Acta\Http\Problem\ExceptionMapper;
use App\Banking\Domain\Exception\BankingRuleViolated;
use Override;
use ReflectionClass;
use Throwable;

/**
 * Reports a rule of the bank that was not respected as a 422, with the words of the
 * domain as the detail. The domain itself knows nothing of HTTP.
 */
final readonly class BusinessRuleMapper implements ExceptionMapper
{
    #[Override]
    public function map(Throwable $exception): ?HttpException
    {
        return $exception instanceof BankingRuleViolated
            ? new HttpException(
                422,
                $exception->getMessage(),
                type: 'urn:problem:banking:' . self::slug($exception),
                title: 'Business rule violated',
                previous: $exception,
            )
            : null;
    }

    /**
     * Turns `InsufficientFunds` into `insufficient-funds`, a stable identifier clients can test.
     */
    private static function slug(BankingRuleViolated $exception): string
    {
        return new ReflectionClass($exception)->getShortName()
            |> (static fn(string $name): string => (string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name))
            |> strtolower(...);
    }
}
