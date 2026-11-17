<?php

declare(strict_types=1);

namespace App\Platform\Log;

use DateTimeInterface;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * Writes one JSON object per line to a stream, standard error by default.
 *
 * It is all a containerised application needs: the platform collects the stream. Swap it
 * for Monolog, or any other PSR-3 logger, by changing the `LoggerInterface` service.
 */
final class StreamLogger extends AbstractLogger
{
    private const array SEVERITY = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /** @var resource|null */
    private $handle = null;

    /**
     * @param string $stream   where to write, `php://stderr` for instance
     * @param string $minLevel PSR-3 level under which records are dropped
     */
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly string $stream = 'php://stderr',
        private readonly string $minLevel = LogLevel::INFO,
    ) {}

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $level = is_string($level) ? $level : LogLevel::INFO;

        if ((self::SEVERITY[$level] ?? 1) < (self::SEVERITY[$this->minLevel] ?? 1)) {
            return;
        }

        $record = [
            'time' => $this->clock->now()->format(DateTimeInterface::RFC3339_EXTENDED),
            'level' => $level,
            'message' => self::interpolate((string) $message, $context),
            'context' => array_map(self::normalize(...), $context),
        ];

        $this->handle ??= @fopen($this->stream, 'a') ?: null;

        if ($this->handle !== null) {
            fwrite($this->handle, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
        }
    }

    /**
     * Replaces the `{placeholders}` of PSR-3 with the scalar values of the context.
     *
     * @param array<string, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Throwable => [
                'class' => $value::class,
                'message' => $value->getMessage(),
                'file' => $value->getFile() . ':' . $value->getLine(),
            ],
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::RFC3339_EXTENDED),
            $value instanceof Stringable => (string) $value,
            is_object($value) => $value::class,
            default => $value,
        };
    }
}
