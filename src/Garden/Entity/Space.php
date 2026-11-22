<?php

namespace App\Garden\Entity;

use App\Garden\Model\Soil;
use App\Garden\SpaceRepository;
use App\Plant\Model\Exposure;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A spot of a garden (the balcony, the north bed...): its exposure and its soil are those of the plants
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
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Garden $garden,
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
    public static function proposed(Garden $garden, string $proposalId, string $name, ?Exposure $exposure, ?Soil $soil, \DateTimeImmutable $createdAt): self
    {
        $space = new self($garden, $name, $exposure, $soil, $createdAt);
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
    public function describeConditions(TranslatorInterface $translator): ?string
    {
        if (null === $this->exposure) {
            return null !== $this->soil ? $translator->trans('space.conditions.soil_only', self::soilParameters($this->soil, $translator)) : null;
        }

        return null !== $this->soil
            ? $translator->trans('space.conditions.exposure_and_soil', ['exposure' => $this->exposure->trans($translator), ...self::soilParameters($this->soil, $translator)])
            : $this->exposure->trans($translator);
    }

    /**
     * Only for Planting, which owns the relation: call Planting::revise() instead.
     *
     * @internal
     */
    public function welcome(Planting $planting): void
    {
        if ($planting->getGarden() !== $this->garden) {
            throw new \LogicException('A planting cannot grow in a space of another garden.');
        }

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

    public function getGarden(): Garden
    {
        return $this->garden;
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

    /**
     * Its label as is, and in lower case within a sentence: "Clay soil", "Sol argileux", "Full sun, clay".
     *
     * @return array{soil: string, soil_lower: string}
     */
    private static function soilParameters(Soil $soil, TranslatorInterface $translator): array
    {
        $label = $soil->trans($translator);

        return ['soil' => $label, 'soil_lower' => mb_strtolower($label)];
    }
}
