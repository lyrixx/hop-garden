<?php

namespace App\Tests\Utils\Factory;

use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Plant\Entity\Plant;

final class PlantingFactory
{
    /**
     * A plant of the garden following this care sheet, named after it.
     */
    public static function of(Plant $plant, ?Space $space = null): Planting
    {
        $planting = new Planting($plant->getCommonName(), $space, null, $plant->getValidatedAt() ?? $plant->getCreatedAt(), $plant->getTrefleId(), $plant->getScientificName());
        $planting->linkTo($plant);

        return $planting;
    }
}
