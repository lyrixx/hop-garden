<?php

namespace App\Garden;

use App\Plant\Entity\Plant;
use App\Plant\Exception\PlantAlreadyValidatedException;
use App\Plant\PlantRepository;
use Psr\Clock\ClockInterface;

/**
 * The user validates a care sheet, and only the care sheet: a plant of the garden is added apart, with the "Add to my
 * garden" card of the chat or the garden form. One proposed from the garden form already has its planting.
 */
final readonly class PlantValidation
{
    public function __construct(
        private ClockInterface $clock,
        private PlantRepository $plantRepository,
    ) {
    }

    /**
     * To call in a transaction, flushed before it commits: a double click waits for the first validation, then fails.
     *
     * @throws PlantAlreadyValidatedException
     */
    public function validate(Plant $plant): void
    {
        $this->plantRepository->lock($plant);
        $plant->validate($this->clock->now());
    }
}
