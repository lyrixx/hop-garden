<?php

namespace App\Garden;

use App\Garden\Model\Planting;
use App\Plant\Exception\PlantAlreadyValidatedException;
use App\Plant\Model\Plant;
use App\Plant\PlantRepository;
use Psr\Clock\ClockInterface;

/**
 * The user validates a care sheet: the plant joins the garden. One proposed from the garden form already has its
 * planting; one from the chat, or from an external MCP client, gets one.
 */
final readonly class PlantValidation
{
    public function __construct(
        private ClockInterface $clock,
        private PlantingRepository $plantingRepository,
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

        if (!$this->plantingRepository->existsForPlant($plant)) {
            $this->plantingRepository->add(Planting::forValidatedPlant($plant));
        }
    }
}
