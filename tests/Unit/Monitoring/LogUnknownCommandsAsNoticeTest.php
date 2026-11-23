<?php

namespace App\Tests\Unit\Monitoring;

use App\Monitoring\LogUnknownCommandsAsNotice;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\NamespaceNotFoundException;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;

final class LogUnknownCommandsAsNoticeTest extends TestCase
{
    #[DataProvider('provideUnknownCommandsAreNoticesCases')]
    public function testUnknownCommandsAreNotices(\Throwable $exception): void
    {
        $record = new LogUnknownCommandsAsNotice()($this->record($exception));

        self::assertSame(Level::Notice, $record->level);
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function provideUnknownCommandsAreNoticesCases(): iterable
    {
        yield 'a namespace typed as a command' => [new CommandNotFoundException('Command "messenger" is not defined.')];
        yield 'an unknown command' => [new CommandNotFoundException('Command "messenger:faild:show" is not defined.')];
        yield 'an unknown namespace' => [new NamespaceNotFoundException('There are no commands defined in the "mesenger" namespace.')];
    }

    #[DataProvider('provideOtherRecordsAreUnchangedCases')]
    public function testOtherRecordsAreUnchanged(?\Throwable $exception): void
    {
        $record = $this->record($exception);

        self::assertSame($record, new LogUnknownCommandsAsNotice()($record));
    }

    /** @return iterable<string, array{?\Throwable}> */
    public static function provideOtherRecordsAreUnchangedCases(): iterable
    {
        yield 'an unknown option, as the failure of a command' => [new ConsoleRuntimeException('The "--emial" option does not exist.')];
        yield 'the failure of a command' => [new ConsoleRuntimeException('The "failed" receiver does not support listing or showing specific messages.')];
        yield 'a bug' => [new \LogicException('Boom.')];
        yield 'no exception' => [null];
    }

    private function record(?\Throwable $exception): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'console',
            level: Level::Critical,
            message: 'Error thrown while running command "{command}". Message: "{message}"',
            context: null === $exception ? [] : ['exception' => $exception],
        );
    }
}
