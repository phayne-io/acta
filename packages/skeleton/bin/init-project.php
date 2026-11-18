<?php

declare(strict_types=1);

/*
 * Names a project that was just created from the skeleton:
 *
 *     composer create-project acta/skeleton my-shop
 *
 * Composer runs this once, after the dependencies are installed. It asks what the
 * application is called and under which domain the local stack answers, then:
 *
 * - writes the three names to .env.dist and .env (COMPOSE_PROJECT_NAME, APP_NAME,
 *   APP_DOMAIN), from which the Docker stack derives everything else;
 * - puts them in the OpenAPI contract, composer.json, the README and the docs;
 * - removes what only makes sense inside the Acta monorepo: the path repositories of
 *   composer.json and compose.monorepo.yaml;
 * - removes itself and its test.
 *
 * Without a terminal, or with --no-interaction, nothing is asked: the name is the one of
 * the directory, unless APP_NAME and APP_DOMAIN are set in the environment.
 *
 * It refuses to run inside the monorepo, where it would take the skeleton itself apart.
 */

/** The names the skeleton carries until a project replaces them. */
const TEMPLATE_NAME = 'MyBank';
const TEMPLATE_SLUG = 'mybank';
const TEMPLATE_DOMAIN = 'mybank.io';

exit(main(dirname(__DIR__)));

function main(string $root): int
{
    if (is_file(dirname($root) . '/event-sourcing/composer.json')) {
        fwrite(STDERR, "This is the skeleton inside the Acta monorepo: nothing to name. Left untouched.\n");

        return 0;
    }

    $interactive = stream_isatty(STDIN) && !in_array(getenv('COMPOSER_NO_INTERACTION'), ['1', 'true'], true);

    $name = answer('Name of the application', environment('APP_NAME') ?? basename($root), $interactive && environment('APP_NAME') === null);
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

    if ($slug === '') {
        fwrite(STDERR, sprintf("\"%s\" has no letter or digit to name the containers after.\n", $name));

        return 1;
    }

    $domain = strtolower(answer('Domain of the local stack', environment('APP_DOMAIN') ?? $slug . '.test', $interactive && environment('APP_DOMAIN') === null));

    if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain) !== 1) {
        fwrite(STDERR, sprintf("\"%s\" is not a domain name, such as %s.test.\n", $domain, $slug));

        return 1;
    }

    // The settings: a value with a space must be quoted for Docker Compose and the shell.
    rewrite($root . '/.env.dist', static fn(string $env): string => (string) preg_replace(
        ['/^COMPOSE_PROJECT_NAME=.*$/m', '/^APP_NAME=.*$/m', '/^APP_DOMAIN=.*$/m'],
        [
            'COMPOSE_PROJECT_NAME=' . $slug,
            'APP_NAME=' . addcslashes(preg_match('/^[\w.-]+$/', $name) === 1 ? $name : '"' . addcslashes($name, '"\\$') . '"', '\\$'),
            'APP_DOMAIN=' . $domain,
        ],
        $env,
    ));
    copy($root . '/.env.dist', $root . '/.env');

    // The contract: its title and the address the API is served at.
    rewrite($root . '/openapi/openapi.yaml', static fn(string $contract): string => (string) preg_replace(
        ['/^  title: .*$/m', '/^  - url: .*$/m'],
        [
            '  title: ' . addcslashes((string) json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '\\$'),
            '  - url: https://api.' . $domain,
        ],
        $contract,
        1,
    ));

    // What is written for people.
    foreach ([$root . '/README.md', ...(glob($root . '/docs/*.md') ?: [])] as $page) {
        rewrite($page, static fn(string $text): string => str_replace(
            [TEMPLATE_DOMAIN, TEMPLATE_NAME, TEMPLATE_SLUG],
            [$domain, $name, $slug],
            $text,
        ));
    }

    rewrite($root . '/composer.json', static function (string $json) use ($name, $slug): string {
        $manifest = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

        if (!$manifest instanceof stdClass) {
            return $json;
        }

        $manifest->name = 'app/' . $slug;
        $manifest->description = $name;
        // The libraries now come from Packagist, like any other dependency.
        unset($manifest->repositories, $manifest->keywords);

        if (($manifest->scripts ?? null) instanceof stdClass) {
            unset($manifest->scripts->{'post-create-project-cmd'});
        }

        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    });

    // This script leaves, with what checks it and what points at it.
    rewrite($root . '/.php-cs-fixer.dist.php', static fn(string $config): string => str_replace(
        ", __DIR__ . '/bin/init-project.php'",
        '',
        $config,
    ));

    foreach ([$root . '/compose.monorepo.yaml', $root . '/tests/Platform/InitProjectTest.php', __FILE__] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    fwrite(STDOUT, sprintf(
        "\n%s is ready. Its local stack answers on https://api.%s and https://auth.%s:\n\n    make hosts && make up\n\n",
        $name,
        $domain,
        $domain,
    ));

    return 0;
}

/**
 * The value of an environment variable, or null when it is not set or empty.
 */
function environment(string $variable): ?string
{
    $value = getenv($variable);

    return is_string($value) && trim($value) !== '' ? trim($value) : null;
}

/**
 * Asks a question when someone is there to answer it; the default otherwise.
 */
function answer(string $question, string $default, bool $ask): string
{
    if (!$ask) {
        return $default;
    }

    fwrite(STDOUT, sprintf('%s [%s]: ', $question, $default));
    $line = fgets(STDIN);
    $given = is_string($line) ? trim($line) : '';

    return $given !== '' ? $given : $default;
}

/**
 * Replaces the content of a file by what the function makes of it. A missing file is
 * left alone: a project may have deleted what it does not use.
 *
 * @param callable(string): string $change
 */
function rewrite(string $file, callable $change): void
{
    $content = is_file($file) ? file_get_contents($file) : false;

    if (is_string($content)) {
        file_put_contents($file, $change($content));
    }
}
