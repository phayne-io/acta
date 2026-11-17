<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Fixture;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 logger keeping its records in memory, used to assert on what the library logs.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_string($level) ? $level : 'unknown',
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * Returns the messages logged at a level, with their placeholders replaced as PSR-3
     * describes.
     *
     * @return list<string>
     */
    public function messages(string $level): array
    {
        $messages = [];

        foreach ($this->records as $record) {
            if ($record['level'] !== $level) {
                continue;
            }

            $replacements = [];

            foreach ($record['context'] as $key => $value) {
                if (is_scalar($value)) {
                    $replacements['{' . $key . '}'] = (string) $value;
                }
            }

            $messages[] = strtr($record['message'], $replacements);
        }

        return $messages;
    }

    /**
     * Returns the context of the records logged at a level.
     *
     * @return list<array<mixed>>
     */
    public function contexts(string $level): array
    {
        return array_values(array_map(
            static fn(array $record): array => $record['context'],
            array_filter($this->records, static fn(array $record): bool => $record['level'] === $level),
        ));
    }
}
