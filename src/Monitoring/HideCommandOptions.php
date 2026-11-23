<?php

namespace App\Monitoring;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A command reaches the logs and Sentry by its name only, without its arguments nor its options: app:user:create takes
 * the email and the password of the account, app:garden:adopt-orphans and app:mcp:stdio an email. The bundle puts the
 * whole command line on the scope of every issue of the command ("Full command", the value of a --password filtered,
 * not the email), and Symfony logs it as is with the failure of a command (Console\EventListener\ErrorListener, in the
 * context of the log, which the issue carries).
 */
// After the ConsoleListener of the bundle (128), which sets "Full command"
#[AsEventListener(event: ConsoleEvents::COMMAND, method: 'onCommand', priority: 64)]
#[AsMonologProcessor(channel: 'console')]
final class HideCommandOptions
{
    private ?string $commandName = null;

    public function __construct(
        private readonly HubInterface $hub,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if (null === $this->commandName || !isset($record->context['command'])) {
            return $record;
        }

        return $record->with(context: ['command' => $this->commandName] + $record->context);
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $this->commandName = $event->getCommand()?->getName();
        if (null === $this->commandName) {
            return;
        }

        $commandName = $this->commandName;
        $this->hub->configureScope(static function (Scope $scope) use ($commandName): void {
            $scope->setExtra('Full command', $commandName);
        });
    }
}
