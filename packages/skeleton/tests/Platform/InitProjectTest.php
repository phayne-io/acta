<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `bin/init-project.php`, which names a project created from the skeleton, run against a
 * copy of the files it touches.
 */
#[CoversNothing]
final class InitProjectTest extends TestCase
{
    private const array FILES = [
        'bin/init-project.php',
        '.env.dist',
        'composer.json',
        'compose.monorepo.yaml',
        'openapi/openapi.yaml',
        'README.md',
        'docs/getting-started.md',
        '.php-cs-fixer.dist.php',
        'tests/Platform/InitProjectTest.php',
        'deptrac.yaml',
        'config/config.php',
        'src/Platform/Env.php',
        'src/Platform/Make/MakeQueryCommand.php',
        'tests/Architecture/ContextIsolationTest.php',
        'tests/Platform/MakeCommandsTest.php',
    ];

    private string $parent;

    private string $project;

    protected function setUp(): void
    {
        $this->parent = sys_get_temp_dir() . '/acta-init-' . bin2hex(random_bytes(6));
        $this->project = $this->parent . '/corner-shop';

        foreach (self::FILES as $file) {
            self::copy(dirname(__DIR__, 2) . '/' . $file, $this->project . '/' . $file);
        }
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->parent, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($this->parent);
    }

    public function testTheProjectIsNamedAfterItsDirectoryWhenNobodyIsAsked(): void
    {
        self::assertSame(0, $this->initialize());

        $env = $this->read('.env.dist');
        self::assertStringContainsString("COMPOSE_PROJECT_NAME=corner-shop\nAPP_NAME=corner-shop\nAPP_DOMAIN=corner-shop.test\n", $env);
        self::assertSame($env, $this->read('.env'));
    }

    public function testTheGivenNamesReachEveryFileThatCarriesThem(): void
    {
        self::assertSame(0, $this->initialize(['APP_NAME' => 'Corner Shop & Co', 'APP_DOMAIN' => 'Shop.Example.test']));

        $env = $this->read('.env.dist');
        self::assertStringContainsString("COMPOSE_PROJECT_NAME=corner-shop-co\nAPP_NAME=\"Corner Shop & Co\"\nAPP_DOMAIN=shop.example.test\n", $env);
        // What the stack derives from the three names is left as it is.
        self::assertStringContainsString('OIDC_AUDIENCE=${COMPOSE_PROJECT_NAME}-api', $env);
        self::assertStringContainsString('# acta:webhook-secrets', $env);

        $contract = $this->read('openapi/openapi.yaml');
        self::assertStringContainsString("  title: \"Corner Shop & Co\"\n", $contract);
        self::assertStringContainsString("  - url: https://api.shop.example.test\n", $contract);
        self::assertStringContainsString('# acta:paths', $contract);

        self::assertStringStartsWith("# Corner Shop & Co\n", $this->read('README.md'));
        self::assertStringContainsString('https://api.shop.example.test', $this->read('docs/getting-started.md'));
        self::assertStringNotContainsStringIgnoringCase('mybank', $this->read('README.md') . $this->read('docs/getting-started.md'));
    }

    public function testWhatOnlyServesTheMonorepoIsRemoved(): void
    {
        self::assertSame(0, $this->initialize());

        $manifest = json_decode($this->read('composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame('app/corner-shop', $manifest['name']);
        self::assertArrayNotHasKey('repositories', $manifest);
        self::assertIsArray($manifest['scripts']);
        self::assertArrayNotHasKey('post-create-project-cmd', $manifest['scripts']);
        self::assertArrayHasKey('check', $manifest['scripts']);
        self::assertIsArray($manifest['require']);
        self::assertArrayHasKey('acta/event-sourcing', $manifest['require']);

        self::assertFileDoesNotExist($this->project . '/compose.monorepo.yaml');
        self::assertFileDoesNotExist($this->project . '/bin/init-project.php');
        self::assertFileDoesNotExist($this->project . '/tests/Platform/InitProjectTest.php');
        self::assertStringNotContainsString('init-project', $this->read('.php-cs-fixer.dist.php'));
    }

    public function testTheClassesStayInTheAppNamespaceUnlessAnotherOneIsGiven(): void
    {
        $before = $this->read('src/Platform/Env.php');

        self::assertSame(0, $this->initialize());
        self::assertSame($before, $this->read('src/Platform/Env.php'));
    }

    public function testTheGivenNamespaceReplacesAppHoweverItIsWritten(): void
    {
        self::assertSame(0, $this->initialize(['APP_NAMESPACE' => 'Acme\\Shop']));

        // In code, with one backslash.
        self::assertStringContainsString("namespace Acme\\Shop\\Platform;\n", $this->read('src/Platform/Env.php'));
        self::assertStringContainsString("    Acme\\Shop\\Platform\\ConfigProvider::class,\n", $this->read('config/config.php'));
        // In what the generators write.
        self::assertStringContainsString("'Acme\\Shop\\{{ Context }}\\Application\\Query\\{{ Aggregate }}Directory'", $this->read('src/Platform/Make/MakeQueryCommand.php'));
        // In strings, with two.
        self::assertStringContainsString('"Acme\\\\Shop\\\\Billing\\\\ConfigProvider::class,', $this->read('tests/Platform/MakeCommandsTest.php'));
        self::assertStringContainsString("value: '^Acme\\\\Shop\\\\(?!Platform", $this->read('deptrac.yaml'));
        // In a regular expression inside a string, with four.
        self::assertStringContainsString("'~\\bAcme\\\\\\\\Shop\\\\\\\\('", $this->read('tests/Architecture/ContextIsolationTest.php'));

        $manifest = json_decode($this->read('composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame(
            [['psr-4' => ['Acme\\Shop\\' => 'src/']], ['psr-4' => ['Acme\\Shop\\Tests\\' => 'tests/']]],
            [$manifest['autoload'], $manifest['autoload-dev']],
        );

        foreach (['src/Platform/Env.php', 'src/Platform/Make/MakeQueryCommand.php', 'config/config.php', 'deptrac.yaml', 'composer.json'] as $file) {
            self::assertDoesNotMatchRegularExpression('/\\bApp\\\\/', $this->read($file), $file);
        }
    }

    public function testANamespaceThatIsNotOneIsRefused(): void
    {
        $before = $this->read('.env.dist');

        self::assertSame(1, $this->initialize(['APP_NAMESPACE' => 'acme shop']));
        self::assertSame(1, $this->initialize(['APP_NAMESPACE' => 'Acta\\Shop']));
        self::assertSame($before, $this->read('.env.dist'));
    }

    public function testADomainThatIsNotOneIsRefusedAndNothingIsTouched(): void
    {
        $before = $this->read('.env.dist');

        self::assertSame(1, $this->initialize(['APP_DOMAIN' => 'not a domain']));
        self::assertSame($before, $this->read('.env.dist'));
        self::assertFileExists($this->project . '/compose.monorepo.yaml');
    }

    public function testTheSkeletonInsideTheMonorepoIsLeftUntouched(): void
    {
        self::copy(dirname(__DIR__, 2) . '/composer.json', $this->parent . '/event-sourcing/composer.json');
        $before = $this->read('composer.json');

        self::assertSame(0, $this->initialize());
        self::assertSame($before, $this->read('composer.json'));
        self::assertFileExists($this->project . '/bin/init-project.php');
    }

    /**
     * Runs the script as Composer does without a terminal, and returns its exit code.
     *
     * @param array<string, string> $names
     */
    private function initialize(array $names = []): int
    {
        $process = proc_open(
            [PHP_BINARY, $this->project . '/bin/init-project.php'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            $this->project,
            ['COMPOSER_NO_INTERACTION' => '1', ...$names],
        );

        self::assertIsResource($process);

        return proc_close($process);
    }

    private function read(string $file): string
    {
        $content = file_get_contents($this->project . '/' . $file);
        self::assertIsString($content);

        return $content;
    }

    private static function copy(string $from, string $to): void
    {
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0o777, true);
        }

        copy($from, $to);
    }
}
