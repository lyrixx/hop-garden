<?php

namespace App\Controller;

use App\Account\Entity\User;
use App\Garden\CurrentGarden;
use App\Garden\Entity\Garden;
use App\Garden\Form\GardenType;
use App\Garden\Form\Model\GardenFormData;
use App\Garden\GardenRepository;
use App\Garden\Security\GardenVoter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The gardens of a member: the wizard of a new one (a first one before any page of the garden: App\Garden\NoGardenListener),
 * the switch between them in the menu, and the settings of its owner. A garden of others answers 404.
 */
final class GardensController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CurrentGarden $currentGarden,
    ) {
    }

    /**
     * Its name and its city: where it grows gives the frost forecast, its timezone the hour of the reminders.
     */
    #[Route('/gardens/new', name: 'garden_create', methods: ['GET', 'POST'])]
    public function create(Request $request, #[CurrentUser] User $user, GardenRepository $gardenRepository, ClockInterface $clock): Response
    {
        $data = GardenFormData::forNewGarden();
        $form = $this->createForm(GardenType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && !self::searched($form)) {
            $garden = Garden::create(trim($data->name), $data->toLocation(), $user, $clock->now());
            $gardenRepository->add($garden);
            $this->entityManager->flush();
            $this->currentGarden->switchTo($garden);

            $this->addFlash('success', new TranslatableMessage('flash.garden.created', ['name' => $garden->getName()]));

            return $this->redirectToRoute('chat', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('gardens/new.html.twig', [
            'form' => $form,
            'first' => [] === $gardenRepository->findOfMember($user),
        ], self::formResponse($form));
    }

    /**
     * From the menu: the pages show this garden from now on.
     */
    #[Route('/gardens/{id}/switch', name: 'garden_switch', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(GardenVoter::VIEW, 'garden', statusCode: Response::HTTP_NOT_FOUND)]
    #[IsCsrfTokenValid('garden_switch', tokenKey: '_token')]
    public function switch(Garden $garden): Response
    {
        $this->currentGarden->switchTo($garden);

        return $this->redirectToRoute('garden', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * Its name and its place, by its owner. Its members: GardenMembersController.
     */
    #[Route('/gardens/{id}/settings', name: 'garden_settings', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(GardenVoter::MANAGE, 'garden', statusCode: Response::HTTP_NOT_FOUND)]
    public function settings(Request $request, Garden $garden): Response
    {
        $data = GardenFormData::fromGarden($garden);
        $form = $this->createForm(GardenType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && !self::searched($form)) {
            $garden->rename(trim($data->name));
            $garden->relocate($data->toLocation());
            $this->entityManager->flush();

            $this->addFlash('success', new TranslatableMessage('flash.garden.saved', ['name' => $garden->getName()]));

            return $this->redirectToRoute('garden_settings', ['id' => $garden->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('gardens/settings.html.twig', ['garden' => $garden, 'form' => $form], self::formResponse($form));
    }

    /**
     * A form sent and shown again, searched or invalid: a 422, which Turbo Drive renders in place.
     *
     * @param FormInterface<GardenFormData> $form
     */
    private static function formResponse(FormInterface $form): Response
    {
        return new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /**
     * The city searched only: the places found are offered, to pick one.
     *
     * @param FormInterface<GardenFormData> $form
     */
    private static function searched(FormInterface $form): bool
    {
        $search = $form->get('search');

        return $search instanceof ClickableInterface && $search->isClicked();
    }
}
