<?php

namespace App\Garden\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Where a garden grows: the place its owner picked, found by the geocoding of Open-Meteo. Its position is the one of the
 * frost forecast, its timezone the one of the reminder check at 7:00.
 */
#[ORM\Embeddable]
final class GardenLocation
{
    public function __construct(
        /**
         * The place as found: "Lyon, Auvergne-Rhône-Alpes, France".
         */
        #[ORM\Column]
        private string $placeName,
        #[ORM\Column]
        private float $latitude,
        #[ORM\Column]
        private float $longitude,
        #[ORM\Column]
        private string $timezone,
    ) {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException(\sprintf('No place on Earth at %F, %F.', $latitude, $longitude));
        }

        try {
            new \DateTimeZone($timezone);
        } catch (\DateInvalidTimeZoneException $e) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a timezone.', $timezone), previous: $e);
        }
    }

    public function getPlaceName(): string
    {
        return $this->placeName;
    }

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }

    public function getTimezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->timezone);
    }
}
