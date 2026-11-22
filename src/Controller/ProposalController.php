<?php

namespace App\Controller;

use App\Garden\CurrentGarden;
use App\Garden\Entity\Planting;
use App\Garden\Form\Model\PlantingProposalFormData;
use App\Garden\Form\Model\SpaceFormData;
use App\Garden\Form\PlantingProposalType;
use App\Garden\Form\SpaceType;
use App\Garden\Model\PlantingProposal;
use App\Garden\Model\Soil;
use App\Garden\Model\SpaceProposal;
use App\Garden\PlantingRepository;
use App\Garden\Security\GardenOfTheResource;
use App\Garden\Security\GardenResourceVoter;
use App\Garden\Slugs;
use App\Garden\SpaceRepository;
use App\Plant\Model\Exposure;
use App\Plant\PlantRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Uid\Uuid;

/**
 * The cards the agent proposes to add to the garden: nothing is written until the user taps one. Each card is loaded
 * in its Turbo Frame by the browser, in the chat (the worker has no session to sign its form), and answers in it; or
 * shown as a page, from the URL that propose_planting and propose_space give an external MCP client.
 *
 * What the card proposes travels in its URL (see PlantingProposal and SpaceProposal); the row created from it keeps its
 * id, so that the card says "Added" or "Created" once tapped, after a reload too, and a second tap adds nothing.
 *
 * In the current garden: the care sheet and the space of a card are of it, or gone; a card tapped in another garden
 * answers 404, whether it is a garden of the member (a switch first, GardenOfTheResource) or not.
 */
final class ProposalController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly FormFactoryInterface $formFactory,
        private readonly LoggerInterface $logger,
        private readonly CurrentGarden $currentGarden,
        private readonly GardenOfTheResource $gardenOfTheResource,
        private readonly Slugs $slugs,
    ) {
    }

    #[Route('/proposals/plantings/{proposal_id}', name: 'planting_proposal', requirements: ['proposal_id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function planting(
        Request $request,
        string $proposal_id,
        PlantRepository $plantRepository,
        PlantingRepository $plantingRepository,
        SpaceRepository $spaceRepository,
    ): Response {
        $proposal = $this->plantingProposal($proposal_id, $request->query);
        $cardUrl = $this->generateUrl('planting_proposal', $proposal->toArray());

        $planting = $plantingRepository->findOneByProposalId($proposal->proposalId);
        if (null !== $planting) {
            $this->gardenOfTheResource->follow($planting->getGarden());
            if (!$this->isGranted(GardenResourceVoter::PLANTING_VIEW, $planting)) {
                throw $this->createNotFoundException(\sprintf('No planting of the current garden was added from proposal %s.', $proposal->proposalId));
            }

            return $request->isMethod('POST')
                ? $this->redirect($cardUrl, Response::HTTP_SEE_OTHER)
                : $this->card($request, 'Proposal:PlantingCard', 'proposal/planting.html.twig', ['proposal' => $proposal, 'planting' => $planting, 'plant' => $planting->getPlant(), 'space' => $planting->getSpace()]);
        }

        // Deleted since the agent proposed it, a URL made by hand, or of another garden: the same
        $proposed = $plantRepository->find($proposal->plantId);
        if (null !== $proposed) {
            $this->gardenOfTheResource->follow($proposed->getGarden());
        }
        $garden = $this->currentGarden->get();
        $plant = $plantRepository->findOneInGarden($garden, $proposal->plantId);
        $space = null !== $proposal->spaceId ? $spaceRepository->findOneInGarden($garden, $proposal->spaceId) : null;
        $unavailable = match (true) {
            null === $plant => new TranslatableMessage('proposal.planting.care_sheet_gone'),
            !$plant->isValidated() => new TranslatableMessage('proposal.planting.validate_first'),
            null !== $proposal->spaceId && null === $space => new TranslatableMessage('proposal.planting.space_gone'),
            default => null,
        };

        $data = new PlantingProposalFormData($proposal->name);
        $form = $this->formFactory->createNamed(self::formName('planting', $proposal->proposalId), PlantingProposalType::class, $data, ['action' => $cardUrl]);
        $form->handleRequest($request);

        if (null !== $plant && null === $unavailable && $form->isSubmitted() && $form->isValid()) {
            $this->insertOnce(fn () => $plantingRepository->add(Planting::proposed($proposal->proposalId, trim($data->name), $this->slugs->forPlanting($plant->getGarden(), trim($data->name)), $plant, $space, $this->clock->now())));

            return $this->redirect($cardUrl, Response::HTTP_SEE_OTHER);
        }

        return $this->card(
            $request,
            'Proposal:PlantingCard',
            'proposal/planting.html.twig',
            ['proposal' => $proposal, 'planting' => null, 'plant' => $plant, 'space' => $space, 'form' => $form->createView(), 'unavailable' => $unavailable],
            $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }

    #[Route('/proposals/spaces/{proposal_id}', name: 'space_proposal', requirements: ['proposal_id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function space(Request $request, string $proposal_id, SpaceRepository $spaceRepository): Response
    {
        $proposal = self::spaceProposal($proposal_id, $request->query);
        $cardUrl = $this->generateUrl('space_proposal', $proposal->toArray());

        $space = $spaceRepository->findOneByProposalId($proposal->proposalId);
        if (null !== $space) {
            $this->gardenOfTheResource->follow($space->getGarden());
            if (!$this->isGranted(GardenResourceVoter::SPACE_VIEW, $space)) {
                throw $this->createNotFoundException(\sprintf('No space of the current garden was created from proposal %s.', $proposal->proposalId));
            }

            return $request->isMethod('POST')
                ? $this->redirect($cardUrl, Response::HTTP_SEE_OTHER)
                : $this->card($request, 'Proposal:SpaceCard', 'proposal/space.html.twig', ['proposal' => $proposal, 'space' => $space]);
        }

        $data = SpaceFormData::fromProposal($proposal);
        $form = $this->formFactory->createNamed(self::formName('space', $proposal->proposalId), SpaceType::class, $data, [
            'action' => $cardUrl,
            'validation_groups' => ['Default', SpaceFormData::PROPOSAL],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $garden = $this->currentGarden->get();
            $this->insertOnce(fn () => $spaceRepository->add($data->toProposedSpace($garden, $proposal->proposalId, $this->slugs->forSpace($garden, $data->name), $this->clock->now())));

            return $this->redirect($cardUrl, Response::HTTP_SEE_OTHER);
        }

        return $this->card(
            $request,
            'Proposal:SpaceCard',
            'proposal/space.html.twig',
            ['proposal' => $proposal, 'space' => null, 'form' => $form->createView()],
            $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }

    /**
     * In its frame, the card alone; without, a page of its own.
     *
     * @param array<string, mixed> $props
     */
    private function card(Request $request, string $component, string $page, array $props, int $status = Response::HTTP_OK): Response
    {
        if ($request->headers->has('Turbo-Frame')) {
            return $this->render('proposal/frame.html.twig', ['component' => $component, 'props' => $props], new Response(status: $status));
        }

        return $this->render($page, $props, new Response(status: $status));
    }

    /**
     * Found by its proposal id before, but two taps may race: the unique key keeps one row. The entity manager is closed
     * after the failed flush, so the card is read again in the request of the redirect. Its slug is picked in the same
     * transaction: see Slugs.
     *
     * @param \Closure(): void $add
     */
    private function insertOnce(\Closure $add): void
    {
        try {
            $this->entityManager->wrapInTransaction($add);
        } catch (UniqueConstraintViolationException $e) {
            $this->logger->warning('A card was tapped twice at once: the other tap added it.', ['exception' => $e]);
        }
    }

    /**
     * Several cards may show in the chat: each its own form, with its own field ids.
     */
    private static function formName(string $kind, Uuid $proposalId): string
    {
        return $kind . '_' . str_replace('-', '', $proposalId->toRfc4122());
    }

    /**
     * A URL made by hand, its ids malformed: no card.
     *
     * @param InputBag<string> $query
     */
    private function plantingProposal(string $proposalId, InputBag $query): PlantingProposal
    {
        $plantId = $query->getString('plant_id');
        $spaceId = $query->has('space_id') ? $query->getString('space_id') : null;
        if (!Uuid::isValid($plantId) || (null !== $spaceId && !Uuid::isValid($spaceId))) {
            throw $this->createNotFoundException(\sprintf('No card of proposal %s with these ids.', $proposalId));
        }

        return new PlantingProposal(
            Uuid::fromString($proposalId),
            Uuid::fromString($plantId),
            null !== $spaceId ? Uuid::fromString($spaceId) : null,
            $query->getString('name'),
        );
    }

    /**
     * @param InputBag<string> $query
     */
    private static function spaceProposal(string $proposalId, InputBag $query): SpaceProposal
    {
        return new SpaceProposal(
            Uuid::fromString($proposalId),
            $query->getString('name'),
            Exposure::tryFrom($query->getString('exposure')),
            Soil::tryFrom($query->getString('soil')),
        );
    }
}
