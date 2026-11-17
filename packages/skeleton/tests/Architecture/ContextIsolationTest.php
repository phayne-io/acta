<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use FilesystemIterator;
use PhpToken;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Contexts do not reach into one another.
 *
 * Two contexts talk through integration events, or through a port one of them declares
 * and the other implements in its infrastructure — never by using each other's classes
 * in their domain or their application. Deptrac checks the layers inside a context
 * (`composer architecture`); this test checks the borders between contexts, whatever
 * their number.
 */
#[CoversNothing]
final class ContextIsolationTest extends TestCase
{
    /** Namespaces under `App\` that are not business contexts. */
    private const array NOT_CONTEXTS = ['Platform', 'Shared'];

    public function testTheDomainAndTheApplicationOfAContextKnowNoOtherContext(): void
    {
        $source = dirname(__DIR__, 2) . '/src';
        $contexts = array_values(array_diff(array_map(basename(...), glob($source . '/*', GLOB_ONLYDIR) ?: []), self::NOT_CONTEXTS));
        $violations = [];

        self::assertNotSame([], $contexts);

        foreach ($contexts as $context) {
            $others = implode('|', array_map(preg_quote(...), array_diff($contexts, [$context])));

            foreach (['Domain', 'Application'] as $layer) {
                foreach ($others === '' ? [] : self::phpFiles($source . '/' . $context . '/' . $layer) as $file) {
                    if (preg_match_all('~\bApp\\\\(' . $others . ')\\\\[\w\\\\]+~', (string) file_get_contents($file), $matches) > 0) {
                        $violations[] = sprintf('%s uses %s', substr($file, strlen($source) + 1), implode(', ', array_unique($matches[0])));
                    }
                }
            }
        }

        self::assertSame([], $violations);
    }

    public function testThePlatformKnowsNoContext(): void
    {
        $source = dirname(__DIR__, 2) . '/src';
        $violations = [];

        foreach ([...self::phpFiles($source . '/Platform'), ...self::phpFiles($source . '/Shared')] as $file) {
            if (preg_match_all('~\bApp\\\\(?!Platform\\\\|Shared\\\\)\w+\\\\[\w\\\\]+~', self::code($file), $matches) > 0) {
                $violations[] = sprintf('%s uses %s', substr($file, strlen($source) + 1), implode(', ', array_unique($matches[0])));
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Returns the code of a file without its comments, where examples may name anything.
     */
    private static function code(string $file): string
    {
        return implode('', array_map(
            static fn(PhpToken $token): string => $token->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $token->text,
            PhpToken::tokenize((string) file_get_contents($file)),
        ));
    }
}
