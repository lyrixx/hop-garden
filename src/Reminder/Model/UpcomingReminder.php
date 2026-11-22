<?php

namespace App\Reminder\Model;

use App\Reminder\Entity\Reminder;

final readonly class UpcomingReminder
{
    public function __construct(
        public Reminder $reminder,
        public \DateTimeImmutable $startsOn,
    ) {
    }
}
