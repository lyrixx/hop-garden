<?php

namespace App\Plant\Model;

use App\Garden\Entity\Planting;
use App\Plant\Entity\Plant;

/**
 * A species of the index, with my plants that follow its care sheet.
 */
final readonly class PlantWithPlantings
{
    /**
     * @param list<Planting> $plantings by name, their space loaded
     */
    public function __construct(
        public Plant $plant,
        public array $plantings,
    ) {
    }
}
