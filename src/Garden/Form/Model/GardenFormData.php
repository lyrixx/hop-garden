<?php

namespace App\Garden\Form\Model;

use App\DataSource\OpenMeteo\Model\Place;
use App\Garden\Entity\Garden;
use App\Garden\Entity\GardenLocation;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A garden, named and placed: its city searched first (the "search" button, its own validation group), then its place
 * picked among the ones found. A new garden needs one; an existing one keeps its own unless a city is searched.
 */
final class GardenFormData
{
    public const string SEARCH = 'search';

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $name = '';

    #[Assert\NotBlank(groups: [self::SEARCH])]
    #[Assert\Length(max: 100, groups: [self::SEARCH, 'Default'])]
    public ?string $city = null;

    /**
     * The id at Open-Meteo of the place picked among $places.
     */
    public ?int $placeId = null;

    /**
     * Found for the city by the form, App\Garden\Form\GardenType, never sent by the browser.
     *
     * @var list<Place>
     */
    public array $places = [];

    /**
     * The search of the city failed: the form says so, nothing else is wrong.
     */
    public bool $placeSearchUnavailable = false;

    private function __construct(
        /**
         * Where it grows already, for an existing garden.
         */
        public readonly ?GardenLocation $location,
    ) {
    }

    public static function forNewGarden(): self
    {
        return new self(null);
    }

    public static function fromGarden(Garden $garden): self
    {
        $data = new self($garden->getLocation());
        $data->name = $garden->getName();

        return $data;
    }

    public function picked(): ?Place
    {
        foreach ($this->places as $place) {
            if ($place->id === $this->placeId) {
                return $place;
            }
        }

        return null;
    }

    /**
     * The place picked, or the one the garden has already.
     */
    public function toLocation(): GardenLocation
    {
        $place = $this->picked();
        if (null === $place) {
            return $this->location ?? throw new \LogicException('A new garden gets its place in a validated form only.');
        }

        return new GardenLocation($place->label(), $place->latitude, $place->longitude, $place->timezone);
    }

    #[Assert\Callback(groups: [self::SEARCH, 'Default'])]
    public function validatePlace(ExecutionContextInterface $context): void
    {
        $city = trim($this->city ?? '');

        if ($this->placeSearchUnavailable) {
            $context->buildViolation('garden.place.unavailable')->atPath('city')->addViolation();
        } elseif ('' !== $city && [] === $this->places) {
            $context->buildViolation('garden.place.not_found')->setParameter('{{ city }}', $city)->atPath('city')->addViolation();
        } elseif (self::SEARCH === $context->getGroup()) {
            // Searched only: the place is picked next
        } elseif ('' !== $city && null === $this->picked()) {
            $context->buildViolation('garden.place.not_picked')->atPath('placeId')->addViolation();
        } elseif ('' === $city && null === $this->location) {
            $context->buildViolation('garden.place.required')->atPath('city')->addViolation();
        }
    }
}
