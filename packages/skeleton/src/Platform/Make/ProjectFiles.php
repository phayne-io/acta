<?php

declare(strict_types=1);

namespace App\Platform\Make;

use RuntimeException;

/**
 * The files of the project, as the generators see them: something to add to, never to
 * overwrite.
 *
 * Existing files are changed in one way only: lines are inserted above a marker comment
 * such as `// acta:events`. When a marker is gone, the generator says what to add by
 * hand instead of guessing where.
 */
final class ProjectFiles
{
    /** @var list<string> files written, relative to the root */
    public private(set) array $created = [];

    /** @var list<string> files completed, relative to the root */
    public private(set) array $updated = [];

    /** @var list<string> files left alone because they exist, relative to the root */
    public private(set) array $kept = [];

    /** @var list<string> what could not be done and is left to the developer */
    public private(set) array $manual = [];

    public function __construct(
        public readonly string $root,
    ) {}

    public function exists(string $file): bool
    {
        return is_file($this->absolute($file));
    }

    /**
     * @throws RuntimeException when the file cannot be read
     */
    public function read(string $file): string
    {
        $content = @file_get_contents($this->absolute($file));

        return $content !== false ? $content : throw new RuntimeException(sprintf('"%s" cannot be read.', $file));
    }

    /**
     * Writes a new file. An existing file is never replaced.
     *
     * @return bool whether the file was written
     *
     * @throws RuntimeException when the file cannot be written
     */
    public function create(string $file, string $content): bool
    {
        if ($this->exists($file)) {
            $this->kept[] = $file;

            return false;
        }

        $path = $this->absolute($file);

        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0o777, true) && !is_dir(dirname($path))) {
            throw new RuntimeException(sprintf('The directory of "%s" cannot be created.', $file));
        }

        $this->write($file, $content);
        $this->created[] = $file;

        return true;
    }

    /**
     * Inserts text above a marker comment, with the indentation of the marker.
     *
     * Nothing happens when the file already holds the text. When the file or the marker
     * is missing, the insertion is recorded as left to the developer.
     *
     * @param string $marker name of the marker, `events` for the comment `acta:events`
     * @param string $text   one or several lines, without indentation of their own
     */
    public function insert(string $file, string $marker, string $text): void
    {
        $lines = $this->exists($file) ? explode("\n", $this->read($file)) : [];
        $position = array_find_key($lines, static fn(string $line): bool => preg_match('~^\s*(//|#) acta:' . preg_quote($marker, '~') . '\s*$~', $line) === 1);
        $inserted = explode("\n", rtrim($text, "\n"));

        if ($position === null) {
            $this->manual[] = sprintf("%s has no \"acta:%s\" marker. Add there:\n%s", $file, $marker, $text);

            return;
        }

        if (str_contains(implode("\n", array_map(trim(...), $lines)), implode("\n", array_map(trim(...), $inserted)))) {
            return;
        }

        $indentation = substr($lines[$position], 0, (int) strspn($lines[$position], ' '));
        $inserted = array_map(static fn(string $line): string => $line === '' ? '' : $indentation . $line, $inserted);

        array_splice($lines, $position, 0, $inserted);
        $this->write($file, implode("\n", $lines));
        $this->markUpdated($file);
    }

    /**
     * Adds `use` statements to a PHP file, keeping them sorted.
     *
     * @param list<string> $classes fully qualified class names
     */
    public function import(string $file, array $classes): void
    {
        if ($classes === [] || !$this->exists($file)) {
            return;
        }

        $content = $this->read($file);
        $imported = self::withImports($content, $classes);

        if ($imported !== $content) {
            $this->write($file, $imported);
            $this->markUpdated($file);
        }
    }

    /**
     * Returns PHP source with its `use` statements completed and sorted the way
     * PHP-CS-Fixer expects: alphabetically, namespace by namespace.
     *
     * @param list<string> $classes fully qualified class names to import
     */
    public static function withImports(string $source, array $classes = []): string
    {
        $lines = explode("\n", $source);
        $positions = array_keys(array_filter($lines, static fn(string $line): bool => preg_match('/^use [^(]+;$/', $line) === 1));
        $imports = array_map(static fn(int $position): string => $lines[$position], $positions);
        $wanted = array_map(static fn(string $class): string => sprintf('use %s;', ltrim($class, '\\')), $classes);
        $merged = array_values(array_unique([...$imports, ...$wanted]));

        usort($merged, static fn(string $a, string $b): int => strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b)));

        if ($merged === $imports) {
            return $source;
        }

        if ($positions === []) {
            $namespace = array_find_key($lines, static fn(string $line): bool => str_starts_with($line, 'namespace '));
            array_splice($lines, ($namespace ?? 0) + 1, 0, ['', ...$merged]);
        } else {
            array_splice($lines, $positions[0], (int) array_last($positions) - $positions[0] + 1, $merged);
        }

        return implode("\n", $lines);
    }

    private function absolute(string $file): string
    {
        return rtrim($this->root, '/') . '/' . ltrim($file, '/');
    }

    private function write(string $file, string $content): void
    {
        if (@file_put_contents($this->absolute($file), $content) === false) {
            throw new RuntimeException(sprintf('"%s" cannot be written.', $file));
        }
    }

    private function markUpdated(string $file): void
    {
        if (!in_array($file, $this->updated, true) && !in_array($file, $this->created, true)) {
            $this->updated[] = $file;
        }
    }
}
