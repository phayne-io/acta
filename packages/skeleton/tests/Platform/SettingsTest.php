<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Platform\Env;
use App\Platform\Settings;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stringable;

#[CoversClass(Settings::class)]
#[CoversClass(Env::class)]
final class SettingsTest extends TestCase
{
    private Settings $settings;

    protected function setUp(): void
    {
        $this->settings = new Settings([
            'debug' => true,
            'acta' => [
                'root' => '/srv/app',
                'http' => ['contract' => 'openapi/openapi.yaml', 'ttl' => 60, 'absolute' => '/etc/contract.yaml', 'stream' => 'php://stderr'],
                'events' => [self::class, Stringable::class, self::class],
                'tests' => [self::class],
                'commands' => ['a:b' => 'Service'],
                'subscriptions' => [['id' => 'crm']],
                'broken' => ['NoSuchClass'],
            ],
        ]);
    }

    public function testValuesAreReadByPathWithTheirType(): void
    {
        self::assertTrue($this->settings->bool('debug'));
        self::assertSame(60, $this->settings->int('acta.http.ttl'));
        self::assertSame('openapi/openapi.yaml', $this->settings->string('acta.http.contract'));
        self::assertSame(['a:b' => 'Service'], $this->settings->strings('acta.commands'));
        self::assertSame([['id' => 'crm']], $this->settings->records('acta.subscriptions'));
    }

    public function testMissingValuesFallBackOnADefault(): void
    {
        self::assertSame('fallback', $this->settings->string('acta.http.nope', 'fallback'));
        self::assertSame(7, $this->settings->int('acta.nope.deeper', 7));
        self::assertFalse($this->settings->bool('nope'));
        self::assertSame([], $this->settings->strings('acta.nope'));
        self::assertSame([], $this->settings->records('acta.nope'));
    }

    public function testRelativePathsAreTakenFromTheRootOfTheProject(): void
    {
        self::assertSame('/srv/app/openapi/openapi.yaml', $this->settings->path('acta.http.contract'));
        self::assertSame('/etc/contract.yaml', $this->settings->path('acta.http.absolute'));
        self::assertSame('php://stderr', $this->settings->path('acta.http.stream'));
        self::assertSame('', $this->settings->path('acta.http.nope'));
    }

    public function testClassesAreCheckedAndListedOnce(): void
    {
        self::assertSame([self::class, Stringable::class], $this->settings->classes('acta.events'));
        self::assertSame([self::class], $this->settings->implementations('acta.tests', TestCase::class));
    }

    public function testAClassThatDoesNotExistIsAConfigurationDefect(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"acta.broken" names the class "NoSuchClass"');

        $this->settings->classes('acta.broken');
    }

    public function testAClassOfTheWrongTypeIsAConfigurationDefect(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('which is not a ' . LoggerInterface::class);

        $this->settings->implementations('acta.events', LoggerInterface::class);
    }

    public function testAValueOfTheWrongTypeIsAConfigurationDefect(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"acta.http.ttl" must be a string');

        $this->settings->string('acta.http.ttl');
    }

    public function testTheEnvironmentIsReadWithDefaults(): void
    {
        putenv('ACTA_TEST_FLAG=yes');
        putenv('ACTA_TEST_LIST= a, b ,,c ');
        putenv('ACTA_TEST_EMPTY=');
        putenv('ACTA_TEST_NUMBER=42');

        try {
            self::assertTrue(Env::bool('ACTA_TEST_FLAG'));
            self::assertFalse(Env::bool('ACTA_TEST_UNSET'));
            self::assertTrue(Env::bool('ACTA_TEST_UNSET', true));
            self::assertSame(['a', 'b', 'c'], Env::list('ACTA_TEST_LIST'));
            self::assertSame([], Env::list('ACTA_TEST_UNSET'));
            self::assertSame('default', Env::string('ACTA_TEST_EMPTY', 'default'));
            self::assertSame(42, Env::int('ACTA_TEST_NUMBER'));
            self::assertSame(5, Env::int('ACTA_TEST_UNSET', 5));
        } finally {
            foreach (['FLAG', 'LIST', 'EMPTY', 'NUMBER'] as $name) {
                putenv('ACTA_TEST_' . $name);
            }
        }
    }
}
