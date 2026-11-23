<?php

namespace App\Tests\Utils\Garden;

use App\Garden\Entity\Garden;
use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Plant\Entity\Plant;
use App\Story\DefaultGardenStory;

/**
 * The paths of the pages of a garden, as a browser types them: /g/{garden}/..., the resources by their slug.
 */
trait GardenPathsTrait
{
    /**
     * In the garden of the test unless told: "/garden" is "/g/the-garden-of-the-tests/garden".
     */
    private static function inGarden(string $path, ?Garden $garden = null): string
    {
        $garden ??= DefaultGardenStory::get('garden');
        self::assertInstanceOf(Garden::class, $garden);

        return '/g/' . $garden->getSlug() . $path;
    }

    /**
     * The page of a species, a plant or a space, in its garden.
     */
    private static function pathOf(Plant|Planting|Space $resource): string
    {
        return self::inGarden(match (true) {
            $resource instanceof Plant => '/species/',
            $resource instanceof Planting => '/garden/',
            $resource instanceof Space => '/spaces/',
        } . $resource->getSlug(), $resource->getGarden());
    }
}
