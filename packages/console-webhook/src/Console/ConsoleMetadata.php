<?php

declare(strict_types=1);

namespace Acta\Console;

use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\Uuid;

/**
 * Metadata of the messages a console command gives rise to.
 *
 * A business command of the console is a primary adapter like an HTTP handler: it
 * builds a command and dispatches it. This gives its messages the same traceability:
 * who ran the command, and an identifier correlating everything it caused.
 *
 *     $this->commands->dispatch(new CloseAccount($id), ConsoleMetadata::create());
 */
final class ConsoleMetadata
{
    /**
     * @param string|null          $actor who runs the command; the operating system user by default
     * @param array<string, mixed> $more  additional entries
     */
    public static function create(?string $actor = null, array $more = []): Metadata
    {
        return new Metadata([
            ...$more,
            'channel' => 'cli',
            'actor' => $actor ?? get_current_user(),
            Metadata::CORRELATION_ID => Uuid::v7()->value,
        ]);
    }
}
