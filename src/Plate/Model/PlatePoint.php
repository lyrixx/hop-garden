<?php

namespace App\Plate\Model;

/**
 * A point of a plate image, in percent of its width and height.
 */
final readonly class PlatePoint
{
    public function __construct(
        public float $x,
        public float $y,
    ) {
        if ($x < 0 || $x > 100 || $y < 0 || $y > 100) {
            throw new \LogicException(\sprintf('A plate point must lie inside the image, got (%s, %s).', $x, $y));
        }
    }
}
