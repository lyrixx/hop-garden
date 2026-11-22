<?php

namespace App\Story;

use App\Factory\GardenFactory;
use Zenstruck\Foundry\Story;

/**
 * The garden of the factories when none is given (SpaceFactory, PlantFactory, PlantingFactory...): one per test, so
 * that what a test makes grows in the same garden. Its owner is the member App\Tests\Utils\Security\LogInTrait logs in.
 * The demo (DemoStory) gives every garden.
 */
final class DefaultGardenStory extends Story
{
    public function build(): void
    {
        $this->addState('garden', GardenFactory::createOne(['name' => 'The garden of the tests']));
    }
}
