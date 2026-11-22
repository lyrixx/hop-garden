<?php

namespace App\DataSource\OpenMeteo\Model;

/**
 * A place found by the geocoding of Open-Meteo, with its position and its timezone.
 */
final readonly class Place
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $region,
        public ?string $country,
        public float $latitude,
        public float $longitude,
        public string $timezone,
    ) {
    }

    /**
     * "Lyon, Auvergne-Rhône-Alpes, France": a region named as its city once only.
     */
    public function label(): string
    {
        $parts = [$this->name];
        foreach ([$this->region, $this->country] as $part) {
            if (null !== $part && '' !== $part && !\in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }

        return implode(', ', $parts);
    }
}
