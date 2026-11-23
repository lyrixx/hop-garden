<?php

namespace App\Monitoring;

use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The HTTP 4xx (a page not found, an access denied, a garden the member is not in...) do not go to Sentry: they come
 * from the client, not from a bug. Symfony logs them at the error level all the same.
 */
final readonly class IgnoreClientErrors
{
    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $exception = $hint?->exception;

        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return null;
        }

        return $event;
    }
}
