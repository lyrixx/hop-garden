<?php

namespace App\Controller;

use App\Chat\ChatHistory;
use App\Chat\ChatPublisher;
use App\Chat\Model\ChatEntry;
use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\OpenMeteo\Model\FrostForecast;
use App\DataSource\OpenMeteo\OpenMeteoClient;
use App\Garden\Entity\Planting;
use App\Garden\Exception\CareSheetAlreadyLinkedException;
use App\Garden\Form\Model\PlantingFormData;
use App\Garden\Form\PlantingType;
use App\Garden\Message\DraftCareSheet;
use App\Garden\PlantingPhotoStorage;
use App\Garden\PlantingRepository;
use App\Garden\Recommendation\RecommendationEngine;
use App\Locale\PreferredLocale;
use App\Plant\Entity\Plant;
use App\Plant\PlantRepository;
use App\Reminder\ReminderChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Uid\UuidV7;

final class GardenController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly PlantingRepository $plantingRepository,
        private readonly PlantRepository $plantRepository,
        private readonly MessageBusInterface $bus,
        private readonly PreferredLocale $preferredLocale,
    ) {
    }

    /**
     * The plants of the garden, with the drafts of the chat waiting for the user on top. A draft from the garden form
     * waits in the tile of its planting.
     */
    #[Route('/garden', name: 'garden', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('garden/index.html.twig', [
            'drafts' => $this->plantRepository->findDraftsWithoutPlanting(),
            'plantings' => $this->plantingRepository->findAllByName(),
        ]);
    }

    /**
     * A new plant of my garden. From the page of a validated species (?plant=), its species is fixed, with its care
     * sheet; otherwise it is picked with Trefle, or not at all.
     */
    #[Route('/garden/new', name: 'garden_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        PlantingPhotoStorage $photoStorage,
        #[MapQueryParameter(name: 'plant')]
        ?int $plantId = null,
    ): Response {
        $plant = null !== $plantId ? $this->validatedPlant($plantId) : null;
        $data = null !== $plant ? PlantingFormData::forPlant($plant) : new PlantingFormData();
        $form = $this->createForm(PlantingType::class, $data, ['plant' => $plant]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $photoFilename = null !== $data->photo ? $photoStorage->store($data->photo) : null;
            $planting = $data->toPlanting($this->clock->now(), $photoFilename);
            $this->plantingRepository->add($planting);

            // A care sheet already validated for this species: no need for the agent
            $sameSpeciesPlant = $plant ?? $this->sameSpeciesPlants($planting)[0] ?? null;
            if (null !== $sameSpeciesPlant) {
                $planting->linkTo($sameSpeciesPlant);
                $this->entityManager->flush();
                $this->addFlash('success', new TranslatableMessage('flash.planting.added_with_care_sheet', ['name' => $planting->getName(), 'species' => $sameSpeciesPlant->getCommonName()]));
            } else {
                $this->draftCareSheet($planting);
                $this->addFlash('success', new TranslatableMessage('flash.planting.added_drafting', ['name' => $planting->getName()]));
            }

            return $this->redirectToRoute('garden_show', ['id' => $planting->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('garden/new.html.twig', ['form' => $form, 'plant' => $plant]);
    }

    /**
     * What the user sees in the garden, completed or corrected. The species, and so the care sheet, stay.
     */
    #[Route('/garden/{id}/edit', name: 'garden_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Planting $planting, PlantingPhotoStorage $photoStorage): Response
    {
        $data = PlantingFormData::fromPlanting($planting);
        $form = $this->createForm(PlantingType::class, $data, ['planting' => $planting]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $previousPhotoFilename = $planting->getPhotoFilename();
            if (null !== $data->photo) {
                $planting->replacePhoto($photoStorage->store($data->photo));
            }
            $data->applyTo($planting);
            $this->entityManager->flush();

            if (null !== $data->photo && null !== $previousPhotoFilename) {
                $photoStorage->remove($previousPhotoFilename);
            }

            $this->addFlash('success', new TranslatableMessage('flash.planting.saved', ['name' => $planting->getName()]));

            return $this->redirectToRoute('garden_show', ['id' => $planting->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('garden/edit.html.twig', ['planting' => $planting, 'form' => $form]);
    }

    #[Route('/garden/{id}', name: 'garden_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Planting $planting, RecommendationEngine $recommendationEngine, OpenMeteoClient $openMeteoClient, ChatHistory $chatHistory): Response
    {
        $plant = $planting->getPlant();

        $recommendations = null;
        if (null !== $plant && $plant->isValidated()) {
            $recommendations = $recommendationEngine->recommend($planting, $plant->getCareSheet(), $this->clock->now(), $this->frostForecast($openMeteoClient));
        }

        $conversationId = $planting->getConversationId();
        $toolCalls = [];
        if (null === $plant && null !== $conversationId) {
            foreach ($chatHistory->entries(UuidV7::fromString($conversationId)) as $entry) {
                if (ChatEntry::TOOL_CALL === $entry->kind) {
                    $toolCalls[] = $entry->text;
                }
            }
        }

        return $this->render('garden/show.html.twig', [
            'planting' => $planting,
            'recommendations' => $recommendations,
            'same_species_plants' => null === $plant ? $this->sameSpeciesPlants($planting) : [],
            'tool_calls' => $toolCalls,
            'topic' => $planting->isDrafting() && null !== $conversationId ? ChatPublisher::topic($conversationId) : null,
        ]);
    }

    /**
     * Retry, after an attempt that ended without a draft.
     */
    #[Route('/garden/{id}/draft', name: 'garden_draft', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('planting', tokenKey: '_token')]
    public function draft(Planting $planting): Response
    {
        try {
            $this->draftCareSheet($planting);
        } catch (CareSheetAlreadyLinkedException) {
            // Linked meanwhile, in another tab: nothing to draft
        }

        return $this->redirectToRoute('garden_show', ['id' => $planting->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Polled once the page listens to the run: it may have ended before.
     */
    #[Route('/garden/{id}/drafting', name: 'garden_drafting', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function drafting(Planting $planting): JsonResponse
    {
        return $this->json(['drafting' => $planting->isDrafting()]);
    }

    /**
     * Another planting of the same species already has a validated care sheet: no need to draft a new one.
     */
    #[Route('/garden/{id}/plant', name: 'garden_link_plant', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('planting', tokenKey: '_token')]
    public function linkPlant(Request $request, Planting $planting): Response
    {
        $plantId = $request->request->getInt('plant');

        foreach ($this->sameSpeciesPlants($planting) as $plant) {
            if ($plant->getId() === $plantId) {
                $planting->linkTo($plant);
                $this->entityManager->flush();

                $this->addFlash('success', new TranslatableMessage('flash.planting.linked', ['name' => $planting->getName(), 'species' => $plant->getCommonName()]));

                return $this->redirectToRoute('garden_show', ['id' => $planting->getId()], Response::HTTP_SEE_OTHER);
            }
        }

        $this->addFlash('warning', new TranslatableMessage('flash.planting.not_same_species'));

        return $this->redirectToRoute('garden_show', ['id' => $planting->getId()], Response::HTTP_SEE_OTHER);
    }

    private function draftCareSheet(Planting $planting): void
    {
        $conversationId = new UuidV7();
        $planting->startDrafting($conversationId);
        // Committed before the worker reads it
        $this->entityManager->flush();

        $this->bus->dispatch(new DraftCareSheet($planting->getId(), $conversationId->toRfc4122(), $this->preferredLocale->get()->value));
    }

    private function validatedPlant(int $id): Plant
    {
        $plant = $this->plantRepository->find($id);
        if (null === $plant || !$plant->isValidated()) {
            throw $this->createNotFoundException(\sprintf('No validated plant with id %d.', $id));
        }

        return $plant;
    }

    /**
     * @return list<Plant>
     */
    private function sameSpeciesPlants(Planting $planting): array
    {
        $scientificName = $planting->getScientificName();

        return null !== $scientificName ? $this->plantRepository->findValidatedBySpecies($scientificName) : [];
    }

    private function frostForecast(OpenMeteoClient $openMeteoClient): ?FrostForecast
    {
        try {
            return $openMeteoClient->getFrostForecast(ReminderChecker::FROST_FORECAST_DAYS);
        } catch (DataSourceUnavailableException) {
            // Already logged by the client: the frost advice falls back on the hardiness
            return null;
        }
    }
}
