<?php

declare(strict_types=1);

namespace Acta\Webhook\Outgoing;

use Acta\Webhook\Exception\ConfigurationError;
use Closure;

/**
 * How long to wait between the attempts of a delivery, and when to give up.
 *
 * The default schedule is the one the Standard Webhooks specification suggests: 5
 * seconds, 5 minutes, 30 minutes, 2 hours, 5 hours, then 10 hours twice; eight attempts
 * over a little more than a day, which rides out most outages of a subscriber.
 */
final readonly class RetryPolicy
{
    /** @var Closure(): float */
    private Closure $random;

    /**
     * @param list<int>               $delays seconds to wait after the first attempt, the second, and so on; the
     *                                        delivery is given up once the list is exhausted
     * @param float                   $jitter share of each delay that is randomized, between 0 and 1, so that
     *                                        deliveries failing together are not retried together
     * @param (Closure(): float)|null $random returns a number between 0 and 1; for tests
     *
     * @throws ConfigurationError when a delay is negative or the jitter out of range
     */
    public function __construct(
        private array $delays = [5, 300, 1800, 7200, 18000, 36000, 36000],
        private float $jitter = 0.1,
        ?Closure $random = null,
    ) {
        if ($jitter < 0 || $jitter > 1 || array_any($delays, static fn(int $delay): bool => $delay < 0)) {
            throw new ConfigurationError('Retry delays cannot be negative and the jitter must be between 0 and 1.');
        }

        $this->random = $random ?? static fn(): float => mt_rand() / mt_getrandmax();
    }

    /**
     * Builds a policy whose delays grow geometrically.
     *
     * @param int   $maxAttempts attempts made before giving up, the first one included
     * @param int   $firstDelay  seconds to wait after the first attempt
     * @param float $factor      by how much each delay is multiplied
     * @param int   $maxDelay    upper bound of a delay, in seconds
     */
    public static function exponential(int $maxAttempts = 8, int $firstDelay = 5, float $factor = 4.0, int $maxDelay = 36000, float $jitter = 0.1): self
    {
        $delays = [];

        for ($retry = 0; $retry < $maxAttempts - 1; ++$retry) {
            $delays[] = (int) min($maxDelay, $firstDelay * $factor ** $retry);
        }

        return new self($delays, $jitter);
    }

    /**
     * How many attempts are made before giving up, the first one included.
     */
    public function maxAttempts(): int
    {
        return count($this->delays) + 1;
    }

    /**
     * Returns how many seconds to wait after a failed attempt, or null to give up.
     *
     * @param int $attempts attempts made so far, the one that just failed included
     */
    public function delayAfter(int $attempts): ?int
    {
        $delay = $this->delays[$attempts - 1] ?? null;

        if ($delay === null || $attempts < 1) {
            return null;
        }

        return (int) round($delay * (1 + $this->jitter * (2 * ($this->random)() - 1)));
    }
}
