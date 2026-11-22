<?php

namespace App\Reminder\Model;

final readonly class UpcomingReminder
{
    public function __construct(
        public Reminder $reminder,
        public \DateTimeImmutable $startsOn,
    ) {
    }
}
