<?php

namespace App\DataSource\Trefle\Model;

final readonly class PlantCandidate
{
    public function __construct(
        public int $trefleId,
        public string $scientificName,
        public ?string $commonName,
        public ?string $family,
        public ?string $imageUrl,
    ) {
    }
}
