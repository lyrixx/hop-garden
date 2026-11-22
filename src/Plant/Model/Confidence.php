<?php

namespace App\Plant\Model;

enum Confidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function needsReview(): bool
    {
        return self::High !== $this;
    }
}
