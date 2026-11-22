<?php

namespace App\Plate\Model;

/**
 * A numbered figure of a plate: a leader line runs from its anchor on the drawing to its number in the margin.
 */
final readonly class PlateFigure
{
    public function __construct(
        public int $number,
        public PlatePart $part,
        public PlatePoint $anchor,
        public PlateSide $side,
        public float $numberY,
    ) {
    }
}
