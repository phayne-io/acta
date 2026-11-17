<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use Acta\Http\OpenApi\ContractLinter;
use Acta\Http\OpenApi\ContractLoader;
use App\Platform\Make\MakeAggregateCommand;
use App\Platform\Make\MakeCommand;
use App\Platform\Make\MakeCommandCommand;
use App\Platform\Make\MakeContextCommand;
use App\Platform\Make\MakeQueryCommand;
use App\Platform\Make\MakeWebhookCommand;
use App\Platform\Make\Naming;
use App\Platform\Make\ProjectFiles;
use App\Platform\Make\Scaffold;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The generators, run against a copy of the files they touch.
 *
 * That the code they write passes the coding standard, the static analysis and the
 * architecture rules is checked by the continuous integration, which runs them on a
 * real checkout: see `.github/workflows/ci.yml`.
 */
#[CoversClass(MakeCommand::class)]
#[CoversClass(MakeContextCommand::class)]
#[CoversClass(MakeAggregateCommand::class)]
#[CoversClass(MakeCommandCommand::class)]
#[CoversClass(MakeQueryCommand::class)]
#[CoversClass(MakeWebhookCommand::class)]
#[CoversClass(ProjectFiles::class)]
#[CoversClass(Scaffold::class)]
#[CoversClass(Naming::class)]
final class MakeCommandsTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->project = sys_get_temp_dir() . '/acta-make-' . bin2hex(random_bytes(6));

        foreach (['config/config.php', 'openapi/openapi.yaml', '.env.dist'] as $file) {
            self::copy($root . '/' . $file, $this->project . '/' . $file);
        }

        foreach (self::files($root . '/templates') as $template) {
            self::copy($template, $this->project . substr($template, strlen($root)));
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse(self::files($this->project, withDirectories: true)) as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }

        rmdir($this->project);
    }

    public function testAContextIsScaffoldedFromTheContractToTheDomain(): void
    {
        $this->make(MakeContextCommand::class, ['context' => 'billing']);
        $this->make(MakeAggregateCommand::class, ['context' => 'Billing', 'aggregate' => 'credit note']);
        $this->make(MakeCommandCommand::class, ['context' => 'Billing', 'name' => 'IssueCreditNote', '--event' => 'CreditNoteIssued']);
        $this->make(MakeQueryCommand::class, ['context' => 'Billing', 'name' => 'GetCreditNote']);
        $this->make(MakeWebhookCommand::class, ['context' => 'Billing', 'source' => 'stripe']);

        foreach ([
            'src/Billing/ConfigProvider.php',
            'src/Billing/Domain/CreditNote.php',
            'src/Billing/Domain/CreditNoteId.php',
            'src/Billing/Domain/CreditNoteRepository.php',
            'src/Billing/Domain/Event/CreditNoteCreated.php',
            'src/Billing/Domain/Event/CreditNoteIssued.php',
            'src/Billing/Domain/Exception/BillingRuleViolated.php',
            'src/Billing/Application/Command/IssueCreditNote.php',
            'src/Billing/Application/Command/IssueCreditNoteHandler.php',
            'src/Billing/Application/Query/GetCreditNote.php',
            'src/Billing/Application/Query/GetCreditNoteHandler.php',
            'src/Billing/Application/Query/CreditNoteView.php',
            'src/Billing/Application/Query/CreditNoteDirectory.php',
            'src/Billing/Infrastructure/Primary/Http/BusinessRuleMapper.php',
            'src/Billing/Infrastructure/Primary/Http/IssueCreditNoteAction.php',
            'src/Billing/Infrastructure/Primary/Http/GetCreditNoteAction.php',
            'src/Billing/Infrastructure/Primary/Console/IssueCreditNoteCommand.php',
            'src/Billing/Infrastructure/Primary/Webhook/StripeTranslator.php',
            'src/Billing/Infrastructure/Primary/Webhook/StripeWebhookSource.php',
            'src/Billing/Infrastructure/Secondary/Persistence/EventSourcedCreditNoteRepository.php',
            'src/Billing/Infrastructure/Secondary/Persistence/CreditNoteDirectoryProjection.php',
            'src/Billing/Infrastructure/Secondary/Persistence/InMemoryCreditNoteDirectory.php',
            'src/Billing/Infrastructure/Secondary/Persistence/PostgresCreditNoteDirectory.php',
            'src/Billing/Infrastructure/Secondary/Persistence/WritableCreditNoteDirectory.php',
            'tests/Billing/Domain/CreditNoteTest.php',
            'tests/Billing/Application/IssueCreditNoteHandlerTest.php',
            'tests/Billing/Infrastructure/CreditNoteDirectoryProjectionTest.php',
            'tests/Billing/Infrastructure/StripeTranslatorTest.php',
        ] as $file) {
            self::assertFileExists($this->project . '/' . $file);
        }

        // Every generated file is PHP that parses, with nothing left to fill in.
        foreach ([...self::files($this->project . '/src'), ...self::files($this->project . '/tests')] as $file) {
            $source = (string) file_get_contents($file);

            self::assertStringNotContainsString('{{', $source, $file);
            self::assertNotSame([], token_get_all($source, TOKEN_PARSE), $file);
        }

        $config = $this->read('src/Billing/ConfigProvider.php');

        foreach ([
            'use App\Billing\Domain\Event\CreditNoteIssued;',
            'CreditNoteCreated::class,',
            'CreditNoteRepository::class => EventSourcedCreditNoteRepository::class,',
            'CreditNoteDirectory::class => WritableCreditNoteDirectory::class,',
            'memory: InMemoryCreditNoteDirectory::class,',
            'IssueCreditNoteHandler::class,',
            'GetCreditNoteHandler::class,',
            'IssueCreditNoteAction::class,',
            "'billing:issue-credit-note' => IssueCreditNoteCommand::class,",
            'CreditNoteDirectoryProjection::NAME => CreditNoteDirectoryProjection::class,',
            'PostgresCreditNoteDirectory::class,',
            'StripeWebhookSource::ID => StripeWebhookSource::create(...),',
            "StripeWebhookSource::NAME => Env::string('WEBHOOK_STRIPE_SECRET'),",
        ] as $line) {
            self::assertStringContainsString($line, $config);
        }

        self::assertStringContainsString("App\\Billing\\ConfigProvider::class,\n    // acta:contexts", $this->read('config/config.php'));
        self::assertStringContainsString("WEBHOOK_STRIPE_SECRET=\n# acta:webhook-secrets", $this->read('.env.dist'));
        self::assertStringContainsString("const string CATEGORY = 'billing_credit_note';", $this->read('src/Billing/Infrastructure/Secondary/Persistence/EventSourcedCreditNoteRepository.php'));
        self::assertStringContainsString("#[MessageName('billing.credit-note-issued')]", $this->read('src/Billing/Domain/Event/CreditNoteIssued.php'));

        // The contract gained the operations, and is still one the server accepts.
        self::assertSame([], ContractLinter::lintFile($this->project . '/openapi/openapi.yaml'));

        $contract = ContractLoader::load($this->project . '/openapi/openapi.yaml');
        self::assertSame('/credit-notes/{creditNoteId}/issue-credit-note', $contract->operation('issueCreditNote')->path);
        self::assertSame('/credit-notes/{creditNoteId}', $contract->operation('getCreditNote')->path);
        self::assertSame([['bearer' => ['billing:read']]], $contract->operation('getCreditNote')->security);
        self::assertSame(['Billing'], $contract->operation('issueCreditNote')->tags);
    }

    public function testNothingIsOverwrittenOrRegisteredTwice(): void
    {
        $this->make(MakeContextCommand::class, ['context' => 'Billing']);
        $this->make(MakeAggregateCommand::class, ['context' => 'Billing', 'aggregate' => 'Invoice']);
        $this->make(MakeQueryCommand::class, ['context' => 'Billing', 'name' => 'GetInvoice']);

        file_put_contents($this->project . '/src/Billing/Domain/Invoice.php', '<?php // mine');
        $config = $this->read('src/Billing/ConfigProvider.php');
        $contract = $this->read('openapi/openapi.yaml');

        $again = $this->make(MakeAggregateCommand::class, ['context' => 'Billing', 'aggregate' => 'Invoice']);
        $this->make(MakeQueryCommand::class, ['context' => 'Billing', 'name' => 'GetInvoice']);
        $this->make(MakeContextCommand::class, ['context' => 'Billing']);

        self::assertStringContainsString('kept as it is', $again->getDisplay());
        self::assertSame('<?php // mine', $this->read('src/Billing/Domain/Invoice.php'));
        self::assertSame($config, $this->read('src/Billing/ConfigProvider.php'));
        self::assertSame($contract, $this->read('openapi/openapi.yaml'));
    }

    public function testASecondQueryReusesTheReadModelOfItsAggregate(): void
    {
        $this->make(MakeContextCommand::class, ['context' => 'Billing']);
        $this->make(MakeAggregateCommand::class, ['context' => 'Billing', 'aggregate' => 'Invoice']);
        $this->make(MakeQueryCommand::class, ['context' => 'Billing', 'name' => 'GetInvoice']);

        $second = $this->make(MakeQueryCommand::class, ['context' => 'Billing', 'name' => 'FindInvoice', '--aggregate' => 'Invoice']);

        self::assertStringContainsString('kept as it is src/Billing/Application/Query/InvoiceView.php', preg_replace('/ +/', ' ', $second->getDisplay()) ?? '');
        self::assertFileExists($this->project . '/src/Billing/Application/Query/FindInvoiceHandler.php');
        self::assertSame(1, substr_count($this->read('src/Billing/ConfigProvider.php'), 'new AdapterChoice('));
    }

    public function testWhatCannotBeGeneratedIsExplained(): void
    {
        self::assertStringContainsString('There is no "Billing" context', $this->make(MakeAggregateCommand::class, ['context' => 'Billing', 'aggregate' => 'Invoice'], 1)->getDisplay());
        self::assertStringContainsString('is not a business context', $this->make(MakeContextCommand::class, ['context' => 'Platform'], 1)->getDisplay());
        self::assertStringContainsString('cannot be turned into a class name', $this->make(MakeContextCommand::class, ['context' => '42'], 1)->getDisplay());

        $this->make(MakeContextCommand::class, ['context' => 'Billing']);
        self::assertStringContainsString('has no aggregate yet', $this->make(MakeCommandCommand::class, ['context' => 'Billing', 'name' => 'Pay'], 1)->getDisplay());

        $this->make(MakeAggregateCommand::class, ['context' => 'Billing', 'aggregate' => 'Invoice']);
        $this->make(MakeAggregateCommand::class, ['context' => 'Billing', 'aggregate' => 'CreditNote']);
        self::assertStringContainsString('several aggregates (CreditNote, Invoice)', $this->make(MakeCommandCommand::class, ['context' => 'Billing', 'name' => 'Pay'], 1)->getDisplay());
        self::assertStringContainsString('has no "Refund" aggregate', $this->make(MakeCommandCommand::class, ['context' => 'Billing', 'name' => 'Pay', '-a' => 'Refund'], 1)->getDisplay());
    }

    public function testAMissingMarkerIsLeftToTheDeveloper(): void
    {
        file_put_contents($this->project . '/config/config.php', str_replace('// acta:contexts', '', $this->read('config/config.php')));

        $output = $this->make(MakeContextCommand::class, ['context' => 'Billing'], 1)->getDisplay();

        self::assertStringContainsString('config/config.php has no "acta:contexts" marker', $output);
        self::assertStringContainsString('App\Billing\ConfigProvider::class,', $output);
        self::assertFileExists($this->project . '/src/Billing/ConfigProvider.php');
    }

    public function testNamesAreSpelledForEachPlace(): void
    {
        self::assertSame('IssueCreditNote', Naming::studly('issue credit-note'));
        self::assertSame('issueCreditNote', Naming::camel('IssueCreditNote'));
        self::assertSame('issue-credit-note', Naming::kebab('IssueCreditNote'));
        self::assertSame('issue_credit_note', Naming::snake('IssueCreditNote'));
        self::assertSame('issue credit note', Naming::words('IssueCreditNote'));
        self::assertSame(['Invoices', 'Policies', 'Addresses', 'Days', 'Boxes'], array_map(Naming::plural(...), ['Invoice', 'Policy', 'Address', 'Day', 'Box']));

        $this->expectException(InvalidArgumentException::class);
        Naming::studly('  ');
    }

    public function testImportsAreKeptInTheOrderTheCodingStandardExpects(): void
    {
        $source = "<?php\n\nnamespace App\\Billing;\n\nuse App\\Billing\\Domain\\Invoice;\nuse Override;\n\nfinal class X {}\n";

        self::assertSame(
            "<?php\n\nnamespace App\\Billing;\n\nuse App\\Billing\\Domain\\Event\\InvoiceCreated;\nuse App\\Billing\\Domain\\Invoice;\nuse App\\Billing\\Infrastructure\\Y;\nuse Override;\n\nfinal class X {}\n",
            ProjectFiles::withImports($source, ['App\Billing\Infrastructure\Y', '\App\Billing\Domain\Event\InvoiceCreated', 'Override']),
        );
        self::assertSame($source, ProjectFiles::withImports($source));
        self::assertStringContainsString("namespace App;\n\nuse Override;\n", ProjectFiles::withImports("<?php\n\nnamespace App;\n\nfinal class X {}\n", ['Override']));
    }

    /**
     * @param class-string<MakeCommand> $command
     * @param array<string, mixed>      $input
     */
    private function make(string $command, array $input, int $expectedStatus = 0): CommandTester
    {
        $tester = new CommandTester(new $command(new ProjectFiles($this->project)));
        $tester->execute($input);

        self::assertSame($expectedStatus, $tester->getStatusCode(), $tester->getDisplay());

        return $tester;
    }

    private function read(string $file): string
    {
        return (string) file_get_contents($this->project . '/' . $file);
    }

    private static function copy(string $from, string $to): void
    {
        is_dir(dirname($to)) || mkdir(dirname($to), recursive: true);
        copy($from, $to);
    }

    /**
     * @return list<string>
     */
    private static function files(string $directory, bool $withDirectories = false): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && ($withDirectories || $file->isFile())) {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }
}
