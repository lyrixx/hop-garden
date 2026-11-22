<?php

namespace App\Controller;

use App\Garden\CurrentGarden;
use App\Garden\PlantingRepository;
use App\Garden\PlantValidation;
use App\Garden\Security\GardenResourceVoter;
use App\Garden\Security\PlantValidationVoter;
use App\Garden\Slugs;
use App\Garden\SpeciesSearch;
use App\Plant\Entity\Plant;
use App\Plant\Exception\PlantAlreadyValidatedException;
use App\Plant\Exception\PlantNotValidatedException;
use App\Plant\Form\CareSheetType;
use App\Plant\Form\Model\CareSheetFormData;
use App\Plant\Form\Model\NewPlantFormData;
use App\Plant\Form\NewPlantType;
use App\Plant\PlantRepository;
use App\Reminder\Model\ReminderType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

/**
 * The care sheets of the species of the current garden. One of another garden answers 404: IsGranted comes before
 * IsCsrfTokenValid, so that a form sent to it says nothing either.
 */
#[Route('/g/{garden}', requirements: ['garden' => Requirement::ASCII_SLUG])]
final class PlantController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlantRepository $plantRepository,
        private readonly CurrentGarden $currentGarden,
    ) {
    }

    /**
     * Every species, validated or still a draft, and my plants of each.
     */
    #[Route('/species', name: 'plant_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('plant/index.html.twig', ['entries' => $this->plantRepository->findAllWithPlantings($this->currentGarden->get())]);
    }

    /**
     * A species by hand, without the assistant: picked with Trefle, then its care sheet filled in on its form. A species
     * that has its care sheet already is not made twice.
     */
    #[Route('/species/new', name: 'plant_new', methods: ['GET', 'POST'])]
    public function new(Request $request, SpeciesSearch $speciesSearch, Slugs $slugs, ClockInterface $clock): Response
    {
        $garden = $this->currentGarden->get();
        $data = new NewPlantFormData();
        $form = $this->createForm(NewPlantType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $candidate = $data->pickedAmong($speciesSearch->search($data->name));

            $existing = $this->plantRepository->findOneBySpecies($garden, $candidate->trefleId, $candidate->scientificName);
            if (null !== $existing) {
                $this->addFlash('success', new TranslatableMessage('flash.species.exists', ['species' => $existing->getScientificName()]));

                return $this->redirectToRoute('plant_show', ['plant' => $existing->getSlug()], Response::HTTP_SEE_OTHER);
            }

            $plant = $this->entityManager->wrapInTransaction(function () use ($data, $garden, $candidate, $slugs, $clock): Plant {
                $plant = $data->toPlant($garden, $candidate, $slugs->forSpecies($garden, $candidate->scientificName), $clock->now());
                $this->plantRepository->add($plant);

                return $plant;
            });

            $this->addFlash('success', new TranslatableMessage('flash.species.draft_ready', ['name' => $plant->getCommonName()]));

            return $this->redirectToRoute('plant_edit', ['plant' => $plant->getSlug()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('plant/new.html.twig', ['form' => $form]);
    }

    /**
     * The species: its card, and my plants that follow it in the garden.
     */
    #[Route('/species/{plant}', name: 'plant_show', requirements: ['plant' => Requirement::ASCII_SLUG], methods: ['GET'])]
    #[IsGranted(GardenResourceVoter::PLANT_VIEW, 'plant', statusCode: Response::HTTP_NOT_FOUND)]
    public function show(#[ValueResolver('in_garden')] Plant $plant, PlantingRepository $plantingRepository): Response
    {
        return $this->render('plant/show.html.twig', [
            'plant' => $plant,
            'plantings' => $plantingRepository->findByPlant($plant),
        ]);
    }

    /**
     * The card in its Turbo Frame: loaded by the browser when the agent proposed a plant, and where its forms (edit,
     * validate, reminders) come back, wherever it shows.
     */
    #[Route('/species/{plant}/card', name: 'plant_card', requirements: ['plant' => Requirement::ASCII_SLUG], methods: ['GET'])]
    #[IsGranted(GardenResourceVoter::PLANT_VIEW, 'plant', statusCode: Response::HTTP_NOT_FOUND)]
    public function card(#[ValueResolver('in_garden')] Plant $plant, ComponentRendererInterface $componentRenderer): Response
    {
        return new Response($componentRenderer->createAndRender('PlantCard', ['plant' => $plant]));
    }

    #[Route('/species/{plant}/validate', name: 'plant_validate', requirements: ['plant' => Requirement::ASCII_SLUG], methods: ['POST'])]
    #[IsGranted(PlantValidationVoter::VALIDATE, 'plant', statusCode: Response::HTTP_NOT_FOUND)]
    #[IsCsrfTokenValid('plant', tokenKey: '_token')]
    public function validate(Request $request, #[ValueResolver('in_garden')] Plant $plant, PlantValidation $plantValidation): Response
    {
        try {
            $this->entityManager->getConnection()->transactional(function () use ($plant, $plantValidation): void {
                $plantValidation->validate($plant);
                $this->entityManager->flush();
            });
        } catch (PlantAlreadyValidatedException) {
            // A double click, or a second tab: validated once
        }

        // Validated in its frame: the card again, validated, with its reminders
        if ($request->headers->has('Turbo-Frame')) {
            return $this->redirectToRoute('plant_card', ['plant' => $plant->getSlug()], Response::HTTP_SEE_OTHER);
        }

        $this->addFlash('success', new TranslatableMessage('flash.species.validated', ['name' => $plant->getCommonName()]));

        return $this->redirectToRoute('plant_show', ['plant' => $plant->getSlug()], Response::HTTP_SEE_OTHER);
    }

    /**
     * In the frame of the card, wherever it shows (the chat, the page of the species): the form takes the place of the
     * card, and the card comes back once saved. Without a frame, a page of its own.
     */
    #[Route('/species/{plant}/edit', name: 'plant_edit', requirements: ['plant' => Requirement::ASCII_SLUG], methods: ['GET', 'POST'])]
    #[IsGranted(GardenResourceVoter::PLANT_EDIT, 'plant', statusCode: Response::HTTP_NOT_FOUND)]
    public function edit(Request $request, #[ValueResolver('in_garden')] Plant $plant): Response
    {
        $inFrame = $request->headers->has('Turbo-Frame');
        $data = CareSheetFormData::fromPlant($plant);
        $form = $this->createForm(CareSheetType::class, $data, ['action' => $this->generateUrl('plant_edit', ['plant' => $plant->getSlug()])]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plant->revise(trim($data->commonName), $data->toCareSheet($plant->getCareSheet()), '' !== trim($data->notes ?? '') ? trim($data->notes ?? '') : null);
            $this->entityManager->flush();

            // The card says it: no message for later
            if ($inFrame) {
                return $this->redirectToRoute('plant_card', ['plant' => $plant->getSlug()], Response::HTTP_SEE_OTHER);
            }

            $this->addFlash('success', new TranslatableMessage($plant->isValidated() ? 'flash.species.updated' : 'flash.species.draft_updated'));

            return $this->redirectToRoute('plant_show', ['plant' => $plant->getSlug()], Response::HTTP_SEE_OTHER);
        }

        // An invalid form comes back with a 422, in its frame or on its page
        return $this->render($inFrame ? 'plant/edit_frame.html.twig' : 'plant/edit.html.twig', ['plant' => $plant, 'form' => $form]);
    }

    #[Route('/species/{plant}/reminders/{type}', name: 'plant_reminder', requirements: ['plant' => Requirement::ASCII_SLUG], methods: ['POST'])]
    #[IsGranted(GardenResourceVoter::PLANT_EDIT, 'plant', statusCode: Response::HTTP_NOT_FOUND)]
    #[IsCsrfTokenValid('plant', tokenKey: '_token')]
    public function reminder(Request $request, #[ValueResolver('in_garden')] Plant $plant, ReminderType $type): Response
    {
        try {
            if ($request->request->getBoolean('enabled')) {
                $plant->enableReminder($type);
            } else {
                $plant->disableReminder($type);
            }
            $this->entityManager->flush();
        } catch (PlantNotValidatedException) {
            $this->addFlash('warning', new TranslatableMessage('flash.species.reminder_needs_validation'));
        }

        // Switched in its frame: the card holds the frame of each reminder
        if ($request->headers->has('Turbo-Frame')) {
            return $this->redirectToRoute('plant_card', ['plant' => $plant->getSlug()], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('plant_show', ['plant' => $plant->getSlug()], Response::HTTP_SEE_OTHER);
    }
}
