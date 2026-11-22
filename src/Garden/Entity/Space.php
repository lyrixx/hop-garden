<?php

namespace App\Garden\Entity;

use App\Garden\Model\Soil;
use App\Garden\SpaceRepository;
use App\Plant\Model\Exposure;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A spot of the user's garden (the balcony, the north bed...): its exposure and its soil are those of the plants
 * growing there, and they are neighbours.
 */
#[ORM\Entity(repositoryClass: SpaceRepository::class)]
class Space
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Kept in sync by Planting::revise(), the owning side.
     *
     * @var Collection<int, Planting>
     */
    #[ORM\OneToMany(targetEntity: Planting::class, mappedBy: 'space')]
    private Collection $plantings;

    /**
     * The space card it was created from: a second tap finds it, and creates nothing.
     */
    #[ORM\Column(nullable: true, unique: true)]
    private ?string $proposalId = null;

    public function __construct(
        #[ORM\Column]
        private string $name,
        /**
         * Unknown until the user tells.
         */
        #[ORM\Column(nullable: true)]
        private ?Exposure $exposure,
        #[ORM\Column(nullable: true)]
        private ?Soil $soil,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->plantings = new ArrayCollection();
    }

    /**
     * Created from the space card the agent proposed, as the user completed it.
     */
    public static function proposed(string $proposalId, string $name, ?Exposure $exposure, ?Soil $soil, \DateTimeImmutable $createdAt): self
    {
        $space = new self($name, $exposure, $soil, $createdAt);
        $space->proposalId = $proposalId;

        return $space;
    }

    public function revise(string $name, ?Exposure $exposure, ?Soil $soil): void
    {
        $this->name = $name;
        $this->exposure = $exposure;
        $this->soil = $soil;
    }

    /**
     * Its exposure and soil in a few words ("Full sun, clay"), or null when neither is known.
     */
    public function describeConditions(): ?string
    {
        if (null === $this->exposure) {
            return null !== $this->soil ? \sprintf('%s soil', $this->soil->label()) : null;
        }

        return null !== $this->soil
            ? \sprintf('%s, %s', $this->exposure->label(), mb_strtolower($this->soil->label()))
            : $this->exposure->label();
    }

    /**
     * Only for Planting, which owns the relation: call Planting::revise() instead.
     *
     * @internal
     */
    public function welcome(Planting $planting): void
    {
        if (!$this->plantings->contains($planting)) {
            $this->plantings->add($planting);
        }
    }

    /**
     * Only for Planting, which owns the relation: call Planting::revise() instead.
     *
     * @internal
     */
    public function release(Planting $planting): void
    {
        $this->plantings->removeElement($planting);
    }

    public function getId(): int
    {
        return $this->id ?? throw new \LogicException('The space has not been persisted yet.');
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getExposure(): ?Exposure
    {
        return $this->exposure;
    }

    public function getSoil(): ?Soil
    {
        return $this->soil;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getProposalId(): ?string
    {
        return $this->proposalId;
    }

    /**
     * @return list<Planting> by name
     */
    public function getPlantings(): array
    {
        $plantings = array_values($this->plantings->toArray());
        usort($plantings, static fn (Planting $a, Planting $b): int => strcasecmp($a->getName(), $b->getName()));

        return $plantings;
    }
}
