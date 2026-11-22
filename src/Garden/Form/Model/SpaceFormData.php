<?php

namespace App\Garden\Form\Model;

use App\Garden\Entity\Space;
use App\Garden\Model\Soil;
use App\Garden\Model\SpaceProposal;
use App\Garden\Validator\NewSpaceName;
use App\Plant\Model\Exposure;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A spot of the garden, as the user describes it.
 */
final class SpaceFormData
{
    // The validation group of the space card of the agent, see App\Controller\ProposalController
    public const string PROPOSAL = 'proposal';

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[NewSpaceName(groups: [self::PROPOSAL])]
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

    /**
     * The card the agent proposed, prefilled: the user completes it.
     */
    public static function fromProposal(SpaceProposal $proposal): self
    {
        $data = new self();
        $data->name = $proposal->name;
        $data->exposure = $proposal->exposure;
        $data->soil = $proposal->soil;

        return $data;
    }

    public function toSpace(\DateTimeImmutable $createdAt): Space
    {
        return new Space(trim($this->name), $this->exposure, $this->soil, $createdAt);
    }

    public function toProposedSpace(string $proposalId, \DateTimeImmutable $createdAt): Space
    {
        return Space::proposed($proposalId, trim($this->name), $this->exposure, $this->soil, $createdAt);
    }

    public function applyTo(Space $space): void
    {
        $space->revise(trim($this->name), $this->exposure, $this->soil);
    }
}
