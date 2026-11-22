<?php

namespace App\Garden\Recommendation\Model;

final readonly class Recommendation
{
    public function __construct(
        public RecommendationLevel $level,
        public RecommendationSubject $subject,
        public string $message,
    ) {
    }
}
