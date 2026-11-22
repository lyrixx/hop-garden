<?php

namespace App\Controller;

use App\Garden\Entity\Space;
use App\Garden\Form\Model\SpaceFormData;
use App\Garden\Form\SpaceType;
use App\Garden\SpaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The spots of the garden: each carries the exposure and the soil of the plants growing there.
 */
final class SpaceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SpaceRepository $spaceRepository,
    ) {
    }

    #[Route('/spaces', name: 'spaces', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('space/index.html.twig', ['spaces' => $this->spaceRepository->findAllByName()]);
    }

    #[Route('/spaces/new', name: 'space_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ClockInterface $clock): Response
    {
        $data = new SpaceFormData();
        $form = $this->createForm(SpaceType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $space = $data->toSpace($clock->now());
            $this->spaceRepository->add($space);
            $this->entityManager->flush();

            $this->addFlash('success', new TranslatableMessage('flash.space.added', ['name' => $space->getName()]));

            return $this->redirectToRoute('space_show', ['id' => $space->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('space/new.html.twig', ['form' => $form]);
    }

    #[Route('/spaces/{id}', name: 'space_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Space $space): Response
    {
        return $this->render('space/show.html.twig', ['space' => $space]);
    }

    #[Route('/spaces/{id}/edit', name: 'space_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
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
