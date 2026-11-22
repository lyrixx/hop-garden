<?php

namespace App\Controller;

use App\Garden\CurrentGarden;
use App\Garden\Entity\Space;
use App\Garden\Form\Model\SpaceFormData;
use App\Garden\Form\SpaceType;
use App\Garden\Security\GardenResourceVoter;
use App\Garden\SpaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The spots of the current garden: each carries the exposure and the soil of the plants growing there. A space of
 * another garden answers 404.
 */
final class SpaceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SpaceRepository $spaceRepository,
        private readonly CurrentGarden $currentGarden,
    ) {
    }

    #[Route('/spaces', name: 'spaces', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('space/index.html.twig', ['spaces' => $this->spaceRepository->findAllByName($this->currentGarden->get())]);
    }

    #[Route('/spaces/new', name: 'space_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ClockInterface $clock): Response
    {
        $garden = $this->currentGarden->get();
        $data = new SpaceFormData();
        $form = $this->createForm(SpaceType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $space = $data->toSpace($garden, $clock->now());
            $this->spaceRepository->add($space);
            $this->entityManager->flush();

            $this->addFlash('success', new TranslatableMessage('flash.space.added', ['name' => $space->getName()]));

            return $this->redirectToRoute('space_show', ['id' => $space->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('space/new.html.twig', ['form' => $form]);
    }

    #[Route('/spaces/{id}', name: 'space_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(GardenResourceVoter::SPACE_VIEW, 'space', statusCode: Response::HTTP_NOT_FOUND)]
    public function show(Space $space): Response
    {
        return $this->render('space/show.html.twig', ['space' => $space]);
    }

    #[Route('/spaces/{id}/edit', name: 'space_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(GardenResourceVoter::SPACE_EDIT, 'space', statusCode: Response::HTTP_NOT_FOUND)]
    public function edit(Request $request, Space $space): Response
    {
        $data = SpaceFormData::fromSpace($space);
        $form = $this->createForm(SpaceType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data->applyTo($space);
            $this->entityManager->flush();

            $this->addFlash('success', new TranslatableMessage('flash.space.saved', ['name' => $space->getName()]));

            return $this->redirectToRoute('space_show', ['id' => $space->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('space/edit.html.twig', ['space' => $space, 'form' => $form]);
    }
}
