<?php

declare(strict_types=1);

namespace Acta\Http\OpenApi;

use Acta\Http\Exception\ContractError;
use JsonException;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a contract from a JSON, YAML or compiled PHP file.
 *
 * YAML needs `symfony/yaml` or the `yaml` extension. Neither is needed in production
 * when the contract is compiled: {@see self::cached()} writes it once as a PHP file
 * that opcache keeps in memory.
 */
final class ContractLoader
{
    /**
     * Loads a contract, choosing the format from the file extension: `.json`, `.yaml`,
     * `.yml`, or `.php` for a file written by {@see self::compile()}.
     *
     * @throws ContractError when the file cannot be read or is not a usable OpenAPI 3.1 description
     */
    public static function load(string $file): Contract
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (!is_file($file) || !is_readable($file)) {
            throw new ContractError(sprintf('The contract file "%s" cannot be read.', $file));
        }

        if ($extension === 'php') {
            $document = require $file;

            return $document instanceof stdClass
                ? Contract::fromDocument($document)
                : throw new ContractError(sprintf('"%s" is not a compiled contract.', $file));
        }

        $content = (string) file_get_contents($file);

        return match ($extension) {
            'json' => self::fromJson($content),
            'yaml', 'yml' => self::fromYaml($content),
            default => throw new ContractError(sprintf('Cannot tell the format of "%s": use .json, .yaml, .yml or .php.', $file)),
        };
    }

    /**
     * @throws ContractError
     */
    public static function fromJson(string $json): Contract
    {
        try {
            $document = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ContractError('The contract is not valid JSON: ' . $exception->getMessage(), previous: $exception);
        }

        return $document instanceof stdClass
            ? Contract::fromDocument($document)
            : throw new ContractError('The contract must be a JSON object.');
    }

    /**
     * @throws ContractError
     */
    public static function fromYaml(string $yaml): Contract
    {
        if (class_exists(Yaml::class)) {
            try {
                $document = Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP);
            } catch (ParseException $exception) {
                throw new ContractError('The contract is not valid YAML: ' . $exception->getMessage(), previous: $exception);
            }
        } elseif (function_exists('yaml_parse')) {
            $document = @yaml_parse($yaml);
        } else {
            throw new ContractError('Reading a YAML contract needs symfony/yaml or the yaml extension.');
        }

        if ($document instanceof stdClass) {
            return Contract::fromDocument($document);
        }

        if (is_array($document) && !array_is_list($document)) {
            $map = [];

            foreach ($document as $key => $value) {
                $map[(string) $key] = $value;
            }

            return Contract::fromDocument($map);
        }

        throw new ContractError('The contract must be a YAML mapping.');
    }

    /**
     * Writes a contract as a PHP file, to be loaded without any parser.
     *
     * The file is replaced atomically, so it can be compiled while requests are served.
     *
     * @throws ContractError when the file cannot be written
     */
    public static function compile(Contract $contract, string $file): void
    {
        $code = sprintf(
            "<?php\n\n// Compiled OpenAPI contract \"%s\" %s. Generated file: do not edit.\n\nreturn %s;\n",
            addcslashes($contract->title, "\n\r\\"),
            addcslashes($contract->version, "\n\r\\"),
            var_export($contract->document, true),
        );
        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temporary, $code) === false || !@rename($temporary, $file)) {
            @unlink($temporary);

            throw new ContractError(sprintf('The compiled contract cannot be written to "%s".', $file));
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }

    /**
     * Loads the compiled contract, compiling the source first when needed.
     *
     * @param string $source    the JSON or YAML description
     * @param string $cacheFile where the compiled contract is kept; must end with `.php`
     * @param bool   $watch     recompile when the source is newer than the cache; turn it off
     *                          in production, where the cache is built at deployment
     *
     * @throws ContractError
     */
    public static function cached(string $source, string $cacheFile, bool $watch = false): Contract
    {
        $fresh = is_file($cacheFile) && (!$watch || filemtime($cacheFile) >= (int) @filemtime($source));

        if ($fresh) {
            return self::load($cacheFile);
        }

        $contract = self::load($source);
        self::compile($contract, $cacheFile);

        return $contract;
    }
}
