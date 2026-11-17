<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Override;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * A logger that keeps its records, so that a test can tell what went wrong.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public private(set) array $records = [];

    /**
     * @param mixed                   $level
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => is_string($level) ? $level : LogLevel::INFO, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * Returns the messages logged as errors or worse.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        return array_values(array_map(
            static fn(array $record): string => $record['message'],
            array_filter($this->records, static fn(array $record): bool => in_array(
                $record['level'],
                [LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY],
                true,
            )),
        ));
    }
}
