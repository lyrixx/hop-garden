<?php

namespace App\Monitoring;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * An unknown command is a typo, not a bug. Symfony logs it as critical all the same (Console\EventListener\ErrorListener),
 * even `bin/console messenger`, which lists the commands of the namespace: a Sentry issue per typo, and the buffer of
 * fingers_crossed flushed on stderr. The log_level of framework.exceptions, which settles that for HTTP, does not apply
 * to the console.
 *
 * An unknown option stays critical: on the command line, it is a RuntimeException, as the failure of a command.
 */
#[AsMonologProcessor(channel: 'console')]
final readonly class LogUnknownCommandsAsNotice
{
    public function __invoke(LogRecord $record): LogRecord
    {
        // NamespaceNotFoundException ("mesenger:consume") too, which extends it
        if (($record->context['exception'] ?? null) instanceof CommandNotFoundException) {
            return $record->with(level: Level::Notice);
        }

        return $record;
    }
}
