<?php

namespace App\Controller;

use App\Account\Entity\User;
use App\Chat\ChatHistory;
use App\Chat\ConversationRepository;
use App\Chat\Entity\Conversation;
use App\Chat\Model\ChatEntry;
use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\OpenMeteo\Model\FrostForecast;
use App\DataSource\OpenMeteo\OpenMeteoClient;
use App\Garden\CurrentGarden;
use App\Garden\Entity\Planting;
use App\Garden\Exception\CareSheetAlreadyLinkedException;
use App\Garden\Form\Model\PlantingFormData;
use App\Garden\Form\PlantingType;
use App\Garden\GardenQuotas;
use App\Garden\Message\DraftCareSheet;
use App\Garden\PlantingPhotoStorage;
use App\Garden\PlantingRepository;
use App\Garden\Recommendation\RecommendationEngine;
use App\Garden\Security\GardenResourceVoter;
use App\Garden\Slugs;
use App\Locale\PreferredLocale;
use App\Mcp\Agent\AgentRunTokens;
use App\Mercure\Topics;
use App\Plant\Entity\Plant;
use App\Plant\PlantRepository;
use App\Reminder\ReminderChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * The plants of the current garden. A planting of another garden answers 404: IsGranted comes before
 * IsCsrfTokenValid, so that a form sent to it says nothing either.
 */
final class GardenController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly CurrentGarden $currentGarden,
        private readonly PlantingRepository $plantingRepository,
        private readonly PlantRepository $plantRepository,
        private readonly ConversationRepository $conversationRepository,
        private readonly GardenQuotas $gardenQuotas,
        private readonly AgentRunTokens $agentRunTokens,
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
        $garden = $this->currentGarden->get();

        return $this->render('garden/index.html.twig', [
            'drafts' => $this->plantRepository->findDraftsWithoutPlanting($garden),
            'plantings' => $this->plantingRepository->findAllByName($garden),
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
        Slugs $slugs,
        #[CurrentUser]
        User $user,
        #[MapQueryParameter(name: 'plant')]
        ?string $plantId = null,
    ): Response {
        $garden = $this->currentGarden->get();
        $plant = null !== $plantId ? $this->validatedPlant($plantId) : null;
        $data = null !== $plant ? PlantingFormData::forPlant($plant) : new PlantingFormData();
        $form = $this->createForm(PlantingType::class, $data, ['garden' => $garden, 'plant' => $plant]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $photoFilename = null !== $data->photo ? $photoStorage->store($data->photo) : null;
            $planting = $this->entityManager->wrapInTransaction(function () use ($data, $garden, $slugs, $photoFilename): Planting {
                $planting = $data->toPlanting($garden, $slugs->forPlanting($garden, $data->name), $this->clock->now(), $photoFilename);
                $this->plantingRepository->add($planting);

                return $planting;
            });

            // A care sheet already validated for this species: no need for the agent
            $sameSpeciesPlant = $plant ?? $this->sameSpeciesPlants($planting)[0] ?? null;
            if (null !== $sameSpeciesPlant) {
                $planting->linkTo($sameSpeciesPlant);
                $this->entityManager->flush();
                $this->addFlash('success', new TranslatableMessage('flash.planting.added_with_care_sheet', ['name' => $planting->getName(), 'species' => $sameSpeciesPlant->getCommonName()]));
            } elseif ($this->draftCareSheet($user, $planting)) {
                $this->addFlash('success', new TranslatableMessage('flash.planting.added_drafting', ['name' => $planting->getName()]));
            } else {
                $this->addFlash('warning', new TranslatableMessage('flash.planting.added_quota_reached', ['name' => $planting->getName()]));
            }

            return $this->redirectToRoute('garden_show', ['id' => $planting->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('garden/new.html.twig', ['form' => $form, 'plant' => $plant]);
    }

    /**
     * What the user sees in the garden, completed or corrected. The species, and so the care sheet, stay.
     */
    #[Route('/garden/{id}/edit', name: 'garden_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(GardenResourceVoter::PLANTING_EDIT, 'planting', statusCode: Response::HTTP_NOT_FOUND)]
    public function edit(Request $request, Planting $planting, PlantingPhotoStorage $photoStorage): Response
    {
        $data = PlantingFormData::fromPlanting($planting);
        $form = $this->createForm(PlantingType::class, $data, ['garden' => $planting->getGarden(), 'planting' => $planting]);
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

    #[Route('/garden/{id}', name: 'garden_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(GardenResourceVoter::PLANTING_VIEW, 'planting', statusCode: Response::HTTP_NOT_FOUND)]
    public function show(Planting $planting, RecommendationEngine $recommendationEngine, OpenMeteoClient $openMeteoClient, ChatHistory $chatHistory, Topics $topics): Response
    {
        $plant = $planting->getPlant();

        $recommendations = null;
        if (null !== $plant && $plant->isValidated()) {
            $recommendations = $recommendationEngine->recommend($planting, $plant->getCareSheet(), $this->clock->now(), $this->frostForecast($openMeteoClient, $planting));
        }

        $conversationId = $planting->getConversationId();
        $toolCalls = [];
        if (null === $plant && null !== $conversationId) {
            foreach ($chatHistory->entries($conversationId) as $entry) {
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
            'topic' => $planting->isDrafting() && null !== $conversationId ? $topics->drafting($planting->getGarden(), $conversationId->toRfc4122()) : null,
        ]);
    }

    /**
     * The photo of a planting, to its members only: out of public/, see PlantingPhotoStorage. Its name changes with the
     * photo, so the browser keeps it as long as it likes, for itself only.
     */
    #[Route('/garden/{id}/photos/{filename}', name: 'garden_photo', requirements: ['id' => Requirement::UUID, 'filename' => PlantingPhotoStorage::FILENAME_REQUIREMENT], methods: ['GET'])]
    #[IsGranted(GardenResourceVoter::PLANTING_VIEW, 'planting', statusCode: Response::HTTP_NOT_FOUND)]
    public function photo(Request $request, Planting $planting, string $filename, PlantingPhotoStorage $photoStorage): BinaryFileResponse
    {
        $path = $filename === $planting->getPhotoFilename() ? $photoStorage->path($filename) : null;
        if (null === $path) {
            throw $this->createNotFoundException(\sprintf('Planting %s has no photo "%s".', $planting->getId(), $filename));
        }

        $response = new BinaryFileResponse($path, autoEtag: true, autoLastModified: true);
        $response->setPrivate();
        $response->setMaxAge(31536000);
        $response->setImmutable();
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Retry, after an attempt that ended without a draft.
     */
    #[Route('/garden/{id}/draft', name: 'garden_draft', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(GardenResourceVoter::PLANTING_EDIT, 'planting', statusCode: Response::HTTP_NOT_FOUND)]
    #[IsCsrfTokenValid('planting', tokenKey: '_token')]
    public function draft(Planting $planting, #[CurrentUser] User $user): Response
    {
        try {
            if (!$this->draftCareSheet($user, $planting)) {
                $this->addFlash('warning', new TranslatableMessage('flash.quota.agent_runs'));
            }
        } catch (CareSheetAlreadyLinkedException) {
            // Linked meanwhile, in another tab: nothing to draft
        }

        return $this->redirectToRoute('garden_show', ['id' => $planting->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Polled once the page listens to the run: it may have ended before.
     */
    #[Route('/garden/{id}/drafting', name: 'garden_drafting', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(GardenResourceVoter::PLANTING_VIEW, 'planting', statusCode: Response::HTTP_NOT_FOUND)]
    public function drafting(Planting $planting): JsonResponse
    {
        return $this->json(['drafting' => $planting->isDrafting()]);
    }

    /**
     * Another planting of the same species already has a validated care sheet: no need to draft a new one.
     */
    #[Route('/garden/{id}/plant', name: 'garden_link_plant', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(GardenResourceVoter::PLANTING_EDIT, 'planting', statusCode: Response::HTTP_NOT_FOUND)]
    #[IsCsrfTokenValid('planting', tokenKey: '_token')]
    public function linkPlant(Request $request, Planting $planting): Response
    {
        $plantId = $request->request->getString('plant');

        // Of its own garden only
        foreach ($this->sameSpeciesPlants($planting) as $plant) {
            if ($plant->getId()->toRfc4122() === $plantId) {
                $planting->linkTo($plant);
                $this->entityManager->flush();

                $this->addFlash('success', new TranslatableMessage('flash.planting.linked', ['name' => $planting->getName(), 'species' => $plant->getCommonName()]));

                return $this->redirectToRoute('garden_show', ['id' => $planting->getId()], Response::HTTP_SEE_OTHER);
            }
        }

        $this->addFlash('warning', new TranslatableMessage('flash.planting.not_same_species'));

        return $this->redirectToRoute('garden_show', ['id' => $planting->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return bool false when the garden has run its agent too often today: the user may retry later
     */
    private function draftCareSheet(User $user, Planting $planting): bool
    {
        $garden = $planting->getGarden();
        if (!$this->gardenQuotas->consumeAgentRun($garden)) {
            $planting->failDrafting();
            $this->entityManager->flush();

            return false;
        }

        $conversationId = new UuidV7();
        $planting->startDrafting($conversationId);
        $this->conversationRepository->add(Conversation::start($conversationId, $user, $garden, $this->clock->now()));
        // Committed before the worker reads it
        $this->entityManager->flush();

        $this->bus->dispatch(new DraftCareSheet(
            $planting->getId()->toRfc4122(),
            $user->getId()->toRfc4122(),
            $garden->getId()->toRfc4122(),
            $conversationId->toRfc4122(),
            $this->preferredLocale->get()->value,
            $this->agentRunTokens->issue($user, $garden, $conversationId),
        ));

        return true;
    }

    /**
     * Of the current garden: a care sheet of another one is no species to plant.
     */
    private function validatedPlant(string $id): Plant
    {
        $plant = Uuid::isValid($id) ? $this->plantRepository->findOneInGarden($this->currentGarden->get(), Uuid::fromString($id)) : null;
        if (null === $plant || !$plant->isValidated()) {
            throw $this->createNotFoundException(\sprintf('No validated plant with id %s.', $id));
        }

        return $plant;
    }

    /**
     * @return list<Plant>
     */
    private function sameSpeciesPlants(Planting $planting): array
    {
        $scientificName = $planting->getScientificName();

        return null !== $scientificName ? $this->plantRepository->findValidatedBySpecies($planting->getGarden(), $scientificName) : [];
    }

    private function frostForecast(OpenMeteoClient $openMeteoClient, Planting $planting): ?FrostForecast
    {
        try {
            return $openMeteoClient->getFrostForecast($planting->getGarden()->getLocation(), ReminderChecker::FROST_FORECAST_DAYS);
        } catch (DataSourceUnavailableException) {
            // Already logged by the client: the frost advice falls back on the hardiness
            return null;
        }
    }
}
