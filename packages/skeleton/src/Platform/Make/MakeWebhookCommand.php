<?php

declare(strict_types=1);

namespace App\Platform\Make;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

/**
 * `bin/console make:webhook Billing stripe` — lets a third party notify a context: the
 * source, with its signature scheme and its secret, and the translator that turns what
 * the third party says into commands of the context.
 */
#[AsCommand('make:webhook', 'Lets a third party send webhooks to a context: source, signature and translator')]
final class MakeWebhookCommand extends MakeCommand
{
    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('context', InputArgument::REQUIRED, 'Name of the context')
            ->addArgument('source', InputArgument::REQUIRED, 'Name of the sender, stripe for instance: webhooks are received at /webhooks/<source>');
    }

    #[Override]
    protected function generate(InputInterface $input, ProjectFiles $files): array
    {
        $source = Naming::studly(self::text($input->getArgument('source')) ?? '');
        $scaffold = $this->context($files, self::text($input->getArgument('context')) ?? '')->with([
            'Source' => $source,
            'source' => Naming::kebab($source),
            'SOURCE' => strtoupper(Naming::snake($source)),
        ]);
        $config = 'src/{{ Context }}/ConfigProvider.php';
        $namespace = 'App\{{ Context }}\Infrastructure\Primary\Webhook\\';

        $scaffold->file('webhook/Translator.php', 'src/{{ Context }}/Infrastructure/Primary/Webhook/{{ Source }}Translator.php');
        $scaffold->file('webhook/WebhookSource.php', 'src/{{ Context }}/Infrastructure/Primary/Webhook/{{ Source }}WebhookSource.php');
        $scaffold->file('webhook/TranslatorTest.php', 'tests/{{ Context }}/Infrastructure/{{ Source }}TranslatorTest.php');

        $scaffold->line($config, 'factories', '{{ Source }}WebhookSource::ID => {{ Source }}WebhookSource::create(...),', [$namespace . '{{ Source }}WebhookSource']);
        $scaffold->line($config, 'webhook-sources', '{{ Source }}WebhookSource::ID,');
        $scaffold->line($config, 'webhook-secrets', "{{ Source }}WebhookSource::NAME => Env::string('WEBHOOK_{{ SOURCE }}_SECRET'),", ['App\Platform\Env']);
        $scaffold->line('.env.dist', 'webhook-secrets', 'WEBHOOK_{{ SOURCE }}_SECRET=');

        return [
            $scaffold->render('Set WEBHOOK_{{ SOURCE }}_SECRET to the secret shared with the sender.'),
            $scaffold->render('Check how the sender signs its webhooks, in {{ Source }}WebhookSource.'),
            $scaffold->render('Turn its events into commands, in {{ Source }}Translator, and make tests/{{ Context }}/Infrastructure/{{ Source }}TranslatorTest.php pass.'),
        ];
    }
}
