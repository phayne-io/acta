<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use Acta\EventSourcing\SystemClock;
use App\Platform\Log\StreamLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(StreamLogger::class)]
final class StreamLoggerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'acta-log');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testARecordIsOneLineOfJson(): void
    {
        $logger = new StreamLogger(new SystemClock(), $this->file);

        $logger->error('Projection {projection} failed: {error}', [
            'projection' => 'banking_accounts',
            'error' => 'boom',
            'exception' => new RuntimeException('boom'),
        ]);

        $lines = file($this->file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(1, $lines);

        $record = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        self::assertSame('error', $record['level']);
        self::assertSame('Projection banking_accounts failed: boom', $record['message']);
        self::assertSame(RuntimeException::class, $record['context']['exception']['class']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT/', $record['time']);
    }

    public function testRecordsUnderTheMinimumLevelAreDropped(): void
    {
        $logger = new StreamLogger(new SystemClock(), $this->file, 'warning');

        $logger->info('quiet');
        $logger->debug('quieter');
        $logger->warning('loud');

        self::assertStringNotContainsString('quiet', (string) file_get_contents($this->file));
        self::assertStringContainsString('loud', (string) file_get_contents($this->file));
    }
}
