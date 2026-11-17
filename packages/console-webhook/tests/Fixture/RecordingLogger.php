<?php

declare(strict_types=1);

namespace Acta\ConsoleWebhook\Tests\Fixture;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 logger keeping what it receives, to assert on it.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param mixed        $level
     * @param array<mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => is_string($level) ? $level : '', 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<string>
     */
    public function levels(): array
    {
        return array_column($this->records, 'level');
    }
}
