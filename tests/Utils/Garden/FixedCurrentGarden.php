<?php

namespace App\Tests\Utils\Garden;

use App\Garden\CurrentGarden;
use App\Garden\Entity\Garden;
use App\Garden\Exception\NoCurrentGardenException;

/**
 * The current garden of a unit test, without request nor security token.
 */
final class FixedCurrentGarden implements CurrentGarden
{
    public function __construct(
        private ?Garden $garden,
    ) {
    }

    public function get(): Garden
    {
        return $this->garden ?? throw NoCurrentGardenException::create();
    }

    public function find(): ?Garden
    {
        return $this->garden;
    }
}
