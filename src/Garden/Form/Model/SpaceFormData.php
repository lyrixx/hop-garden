<?php

namespace App\Garden\Form\Model;

use App\Garden\Model\Soil;
use App\Garden\Model\Space;
use App\Plant\Model\Exposure;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A spot of the garden, as the user describes it.
 */
final class SpaceFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $name = '';

    public ?Exposure $exposure = null;

    public ?Soil $soil = null;

    public static function fromSpace(Space $space): self
    {
        $data = new self();
        $data->name = $space->getName();
        $data->exposure = $space->getExposure();
        $data->soil = $space->getSoil();

        return $data;
    }

    public function toSpace(\DateTimeImmutable $createdAt): Space
    {
        return new Space(trim($this->name), $this->exposure, $this->soil, $createdAt);
    }

    public function applyTo(Space $space): void
    {
        $space->revise(trim($this->name), $this->exposure, $this->soil);
    }
}
