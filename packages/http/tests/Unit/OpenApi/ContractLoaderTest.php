<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Unit\OpenApi;

use Acta\Http\Exception\ContractError;
use Acta\Http\OpenApi\ContractLoader;
use Acta\Http\Tests\HttpTestCase;
use Override;

final class ContractLoaderTest extends HttpTestCase
{
    private const string SOURCE = __DIR__ . '/../../Fixture/openapi.yaml';

    private string $directory;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/acta-http-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    #[Override]
    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    public function testItLoadsYamlAndJson(): void
    {
        $yaml = ContractLoader::load(self::SOURCE);
        $json = $this->directory . '/openapi.json';
        file_put_contents($json, json_encode($yaml->document));

        self::assertEquals($yaml, ContractLoader::load($json));
        self::assertEquals($yaml, ContractLoader::fromYaml((string) file_get_contents(self::SOURCE)));
    }

    public function testACompiledContractIsTheSameContract(): void
    {
        $contract = ContractLoader::load(self::SOURCE);
        $compiled = $this->directory . '/contract.php';

        ContractLoader::compile($contract, $compiled);

        self::assertEquals($contract, ContractLoader::load($compiled));
        self::assertStringContainsString('Compiled OpenAPI contract "Banking API" 1.0.0', (string) file_get_contents($compiled));
        // Nothing is left behind by the atomic replacement.
        self::assertSame([$compiled], glob($this->directory . '/*'));
    }

    public function testTheCacheIsBuiltOnceThenReused(): void
    {
        $source = $this->directory . '/openapi.yaml';
        $cache = $this->directory . '/contract.php';
        copy(self::SOURCE, $source);

        $first = ContractLoader::cached($source, $cache);

        self::assertFileExists($cache);

        // Without watching, the cache wins even though the source changed.
        file_put_contents($source, str_replace('Banking API', 'Renamed API', (string) file_get_contents($source)));
        touch($source, time() + 10);

        self::assertEquals($first, ContractLoader::cached($source, $cache));
        self::assertSame('Renamed API', ContractLoader::cached($source, $cache, watch: true)->title);
        self::assertSame('Renamed API', ContractLoader::cached($source, $cache)->title);
    }

    public function testAMissingFileIsAnError(): void
    {
        $this->expectException(ContractError::class);
        $this->expectExceptionMessage('cannot be read');

        ContractLoader::load($this->directory . '/missing.yaml');
    }

    public function testAnUnknownExtensionIsAnError(): void
    {
        file_put_contents($this->directory . '/openapi.txt', '{}');

        $this->expectException(ContractError::class);
        $this->expectExceptionMessage('Cannot tell the format');

        ContractLoader::load($this->directory . '/openapi.txt');
    }

    public function testAPhpFileThatIsNotACompiledContractIsAnError(): void
    {
        file_put_contents($this->directory . '/contract.php', '<?php return [];');

        $this->expectException(ContractError::class);
        $this->expectExceptionMessage('is not a compiled contract');

        ContractLoader::load($this->directory . '/contract.php');
    }

    public function testInvalidJsonIsAnError(): void
    {
        $this->expectException(ContractError::class);
        $this->expectExceptionMessage('not valid JSON');

        ContractLoader::fromJson('{"openapi": ');
    }

    public function testAJsonListIsNotAContract(): void
    {
        $this->expectException(ContractError::class);
        $this->expectExceptionMessage('must be a JSON object');

        ContractLoader::fromJson('[]');
    }

    public function testInvalidYamlIsAnError(): void
    {
        $this->expectException(ContractError::class);
        $this->expectExceptionMessage('not valid YAML');

        ContractLoader::fromYaml("openapi: 3.1.0\n  paths: [");
    }

    public function testAYamlScalarIsNotAContract(): void
    {
        $this->expectException(ContractError::class);
        $this->expectExceptionMessage('must be a YAML mapping');

        ContractLoader::fromYaml('just a string');
    }

    public function testAnUnwritableCacheIsAnError(): void
    {
        $this->expectException(ContractError::class);
        $this->expectExceptionMessage('cannot be written');

        ContractLoader::compile(self::contract(), $this->directory . '/missing/contract.php');
    }
}
