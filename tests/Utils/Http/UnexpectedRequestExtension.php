<?php

namespace App\Tests\Utils\Http;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Fails the run when a test left an unexpected HTTP request in the ledger of UnexpectedRequestGuard, even if the code
 * under test caught the exception. PHPUnit reports an exception of a subscriber as a PHPUnit warning, and
 * failOnPhpunitWarning (on by default) fails the run.
 */
final class UnexpectedRequestExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscribers(
            new class implements PreparationStartedSubscriber {
                public function notify(PreparationStarted $event): void
                {
                    UnexpectedRequestGuard::takeRequests();
                }
            },
            new class implements FinishedSubscriber {
                public function notify(Finished $event): void
                {
                    $requests = UnexpectedRequestGuard::takeRequests();
                    if ([] !== $requests) {
                        throw new UnexpectedRequestException(\sprintf('%s made HTTP requests that nothing simulates: %s.', $event->test()->id(), implode(', ', $requests)));
                    }
                }
            },
        );
    }
}
