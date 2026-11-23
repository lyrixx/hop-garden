<?php

namespace App\Tests\Unit\Monitoring;

use App\Monitoring\HideCommandOptions;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;

final class HideCommandOptionsTest extends TestCase
{
    private const string COMMAND_LINE = "app:user:create --email='alice@example.com' --password='correct-horse-battery-staple'";

    public function testTheScopeKeepsTheNameOfTheCommandOnly(): void
    {
        $hub = new Hub();
        // As the ConsoleListener of the bundle does before
        $hub->configureScope(static function (Scope $scope): void {
            $scope->setExtra('Full command', self::COMMAND_LINE);
        });

        new HideCommandOptions($hub)->onCommand($this->commandEvent());

        $event = Event::createEvent();
        $hub->configureScope(static function (Scope $scope) use (&$event): void {
            $event = $scope->applyToEvent($event);
        });
        self::assertNotNull($event);
        self::assertSame(['Full command' => 'app:user:create'], $event->getExtra());
    }

    public function testTheLogOfAFailureKeepsTheNameOfTheCommandOnly(): void
    {
        $processor = new HideCommandOptions(new Hub());
        $processor->onCommand($this->commandEvent());

        $exception = new \RuntimeException('Boom.');
        $record = $processor($this->record(['exception' => $exception, 'command' => self::COMMAND_LINE, 'message' => 'Boom.']));

        self::assertSame(['command' => 'app:user:create', 'exception' => $exception, 'message' => 'Boom.'], $record->context);
    }

    public function testOtherLogsAreUnchanged(): void
    {
        $processor = new HideCommandOptions(new Hub());
        $processor->onCommand($this->commandEvent());

        $record = $this->record(['code' => 1]);

        self::assertSame($record, $processor($record));
    }

    public function testAnErrorBeforeAnyCommandIsUnchanged(): void
    {
        $record = $this->record(['command' => self::COMMAND_LINE]);

        self::assertSame($record, new HideCommandOptions(new Hub())($record));
    }

    private function commandEvent(): ConsoleCommandEvent
    {
        $command = new Command('app:user:create')
            ->addOption('email', mode: InputOption::VALUE_REQUIRED)
            ->addOption('password', mode: InputOption::VALUE_REQUIRED)
        ;
        $input = new ArgvInput(['bin/console', 'app:user:create', '--email=alice@example.com', '--password=correct-horse-battery-staple']);

        return new ConsoleCommandEvent($command, $input, new NullOutput());
    }

    /**
     * @param array<string, mixed> $context
     */
    private function record(array $context): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'console',
            level: Level::Critical,
            message: 'Error thrown while running command "{command}". Message: "{message}"',
            context: $context,
        );
    }
}
