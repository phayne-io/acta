<?php

declare(strict_types=1);

namespace Acta\Oidc\Client;

use Acta\Oidc\Exception\ConfigurationError;
use Override;

/**
 * Token storage in a JSON file readable by its owner only: what a command-line tool
 * needs to stay logged in between two runs.
 *
 * The file holds the tokens in clear. Prefer the keychain of the operating system when
 * one is available.
 */
final readonly class FileTokenStorage implements TokenStorage
{
    /**
     * @param string $file path of the file, such as `~/.config/my-tool/tokens.json`, already expanded
     */
    public function __construct(
        private string $file,
    ) {}

    #[Override]
    public function load(): ?TokenSet
    {
        $content = is_file($this->file) ? @file_get_contents($this->file) : false;
        $values = $content === false ? null : json_decode($content, true);

        return is_array($values) ? TokenSet::fromArray($values) : null;
    }

    /**
     * @throws ConfigurationError when the file cannot be written
     */
    #[Override]
    public function save(TokenSet $tokens): void
    {
        $directory = dirname($this->file);
        $temporary = $this->file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new ConfigurationError(sprintf('The directory "%s" cannot be created.', $directory));
        }

        // Created empty with its final permissions, so that the tokens are never world-readable.
        if (!@touch($temporary) || !@chmod($temporary, 0o600)
            || @file_put_contents($temporary, json_encode($tokens->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false
            || !@rename($temporary, $this->file)
        ) {
            @unlink($temporary);

            throw new ConfigurationError(sprintf('The tokens cannot be written to "%s".', $this->file));
        }
    }

    #[Override]
    public function clear(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }
}
