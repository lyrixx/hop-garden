<?php

namespace App\Twig;

use App\Plate\Model\Plate;
use App\Plate\PlateCatalog;
use Twig\Attribute\AsTwigFunction;

final readonly class PlateExtension
{
    public function __construct(
        private PlateCatalog $plateCatalog,
    ) {
    }

    #[AsTwigFunction('plate')]
    public function plate(string $scientificName): Plate
    {
        return $this->plateCatalog->forSpecies($scientificName);
    }
}
