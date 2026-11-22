<?php

namespace App\Garden\Model;

final readonly class CareRecord
{
    public function __construct(
        public CareTask $task,
        public \DateTimeImmutable $doneOn,
    ) {
    }
}
