<?php

declare(strict_types=1);

namespace App\Banking\Infrastructure\Primary\Http;

use Acta\Http\Exception\HttpException;
use Money\Currency;
use Money\Money;

/**
 * The wire format of money: the `Money` and `Currency` schemas of the contract.
 *
 * Amounts travel in minor units, as strings: JSON numbers are not exact.
 */
final class MoneyFormat
{
    /**
     * @return array{amount: string, currency: string}
     */
    public static function encode(Money $money): array
    {
        return ['amount' => $money->getAmount(), 'currency' => $money->getCurrency()->getCode()];
    }

    /**
     * Reads an amount from a request body.
     *
     * The contract has already validated the body; the checks below state its shape
     * again for the type system, and guard a contract that would stop describing it.
     *
     * @throws HttpException 422 when the body holds no amount
     */
    public static function decode(mixed $body): Money
    {
        $amount = is_array($body) ? $body['amount'] ?? null : null;

        return is_string($amount) && ctype_digit($amount)
            ? new Money($amount, self::currency(is_array($body) ? $body['currency'] ?? null : null))
            : throw HttpException::unprocessable('An amount in minor units is expected.');
    }

    /**
     * Reads a currency code, falling back on a default when there is none.
     *
     * @param non-empty-string|null $default
     *
     * @throws HttpException 422 when there is neither a code nor a default
     */
    public static function currency(mixed $code, ?string $default = null): Currency
    {
        $code = is_string($code) && $code !== '' ? $code : $default;

        return $code !== null ? new Currency($code) : throw HttpException::unprocessable('A currency code is expected.');
    }
}
