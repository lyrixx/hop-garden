<?php

namespace App\Reminder\Model;

use App\Reminder\Entity\Reminder;

/**
 * An enabled frost reminder whose plant the forecast threatens, due on the first frost day.
 */
final readonly class UpcomingFrost
{
    public \DateTimeImmutable $startsOn;

    public function __construct(
        public Reminder $reminder,
        public FrostRisk $risk,
    ) {
        $this->startsOn = $risk->firstDay()->date;
    }
}
