<?php

declare(strict_types=1);

namespace App\Platform\Make;

use RuntimeException;

/**
 * Renders the templates of `templates/make` into the project.
 *
 * A template is the file to produce, where `{{ Name }}` stands for a value. The
 * templates are part of the project: change them to make the generators write code the
 * way your team writes it.
 */
final readonly class Scaffold
{
    /**
     * @param array<string, string> $values what each `{{ placeholder }}` stands for
     */
    public function __construct(
        private ProjectFiles $files,
        private array $values,
    ) {}

    /**
     * Returns a scaffold with more values.
     *
     * @param array<string, string> $values
     */
    public function with(array $values): self
    {
        return new self($this->files, [...$this->values, ...$values]);
    }

    public function value(string $name): string
    {
        return $this->values[$name] ?? throw new RuntimeException(sprintf('The scaffold has no "%s" value.', $name));
    }

    /**
     * Writes a file from a template, unless the file exists.
     *
     * @param string $template path of the template under `templates/make`, without its `.stub` extension
     * @param string $target   path of the file in the project; it may hold placeholders
     */
    public function file(string $template, string $target): void
    {
        $target = $this->render($target);
        $content = $this->template($template);

        // The order of imports depends on the names the template was rendered with.
        $this->files->create($target, str_ends_with($target, '.php') ? ProjectFiles::withImports($content) : $content);
    }

    /**
     * Inserts lines above a marker of an existing file.
     *
     * @param string       $file    path of the file in the project; it may hold placeholders
     * @param string       $text    what to insert; it may hold placeholders
     * @param list<string> $imports classes to import in the file; they may hold placeholders
     */
    public function line(string $file, string $marker, string $text, array $imports = []): void
    {
        $file = $this->render($file);

        $this->files->import($file, array_map($this->render(...), $imports));
        $this->files->insert($file, $marker, $this->render($text));
    }

    /**
     * Inserts a rendered template above a marker of an existing file.
     */
    public function block(string $file, string $marker, string $template): void
    {
        $this->files->insert($this->render($file), $marker, $this->template($template));
    }

    public function render(string $text): string
    {
        return (string) preg_replace_callback(
            '/\{\{ ([A-Za-z][A-Za-z_-]*) \}\}/',
            fn(array $match): string => $this->value($match[1]),
            $text,
        );
    }

    private function template(string $name): string
    {
        return $this->render($this->files->read('templates/make/' . $name . '.stub'));
    }
}
