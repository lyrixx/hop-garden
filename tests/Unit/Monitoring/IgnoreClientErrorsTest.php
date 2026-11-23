<?php

namespace App\Tests\Unit\Monitoring;

use App\Monitoring\IgnoreClientErrors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class IgnoreClientErrorsTest extends TestCase
{
    #[DataProvider('provideDropsClientErrorsCases')]
    public function testDropsClientErrors(\Throwable $exception): void
    {
        $hint = EventHint::fromArray(['exception' => $exception]);

        self::assertNull(new IgnoreClientErrors()(Event::createEvent(), $hint));
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function provideDropsClientErrorsCases(): iterable
    {
        yield '400' => [new BadRequestHttpException()];
        yield '403' => [new AccessDeniedHttpException()];
        yield '404' => [new NotFoundHttpException()];
        yield '499' => [new HttpException(499)];
    }

    #[DataProvider('provideKeepsOtherErrorsCases')]
    public function testKeepsOtherErrors(?\Throwable $exception): void
    {
        $event = Event::createEvent();
        $hint = EventHint::fromArray(['exception' => $exception]);

        self::assertSame($event, new IgnoreClientErrors()($event, $hint));
    }

    /** @return iterable<string, array{?\Throwable}> */
    public static function provideKeepsOtherErrorsCases(): iterable
    {
        yield 'HTTP 500' => [new HttpException(500)];
        yield 'HTTP 503' => [new ServiceUnavailableHttpException()];
        yield 'not an HTTP exception' => [new \RuntimeException('Boom.')];
        yield 'no exception, a log message' => [null];
    }

    public function testKeepsEventsWithoutHint(): void
    {
        $event = Event::createEvent();

        self::assertSame($event, new IgnoreClientErrors()($event, null));
    }
}
