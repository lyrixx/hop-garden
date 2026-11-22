<?php

namespace App\Controller;

use App\Account\Entity\User;
use App\Chat\ChatHistory;
use App\Chat\ChatSession;
use App\Chat\ConversationRepository;
use App\Chat\Entity\Conversation;
use App\Chat\Message\RunAgent;
use App\Chat\Model\ChatEntry;
use App\Chat\Model\ChatMessageInput;
use App\Garden\CurrentGarden;
use App\Garden\Entity\Garden;
use App\Garden\GardenQuotas;
use App\Locale\PreferredLocale;
use App\Mcp\Agent\AgentRunTokens;
use App\Mercure\Topics;
use App\Plant\PlantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The chat of the member, in the garden of the URL: a conversation of theirs in it (App\Chat\Entity\Conversation),
 * never one of another member nor of another garden. Two tabs on two gardens chat in each.
 */
#[Route('/g/{garden}', requirements: ['garden' => Requirement::ASCII_SLUG])]
final class ChatController extends AbstractController
{
    public function __construct(
        private readonly ChatSession $chatSession,
        private readonly CurrentGarden $currentGarden,
        private readonly ConversationRepository $conversationRepository,
    ) {
    }

    #[Route('/assistant', name: 'chat', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, ChatHistory $chatHistory, PlantRepository $plantRepository, Topics $topics): Response
    {
        $garden = $this->currentGarden->get();
        $conversationId = $this->ownConversationId($user, $garden);
        $entries = null !== $this->conversationRepository->findOneById($conversationId) ? $chatHistory->entries($conversationId) : [];

        // A proposed plant may have been deleted since (demo reset...)
        $plantIds = array_values(array_filter(array_map(static fn (ChatEntry $entry): ?Uuid => $entry->plantId, $entries)));
        $plants = [];
        foreach ([] !== $plantIds ? $plantRepository->findBy(['garden' => $garden, 'id' => $plantIds]) : [] as $plant) {
            $plants[$plant->getId()->toRfc4122()] = $plant;
        }

        return $this->render('chat/index.html.twig', [
            'garden' => $garden,
            'entries' => $entries,
            'plants' => $plants,
            'topic' => $topics->conversation($user, $conversationId->toRfc4122()),
        ]);
    }

    /**
     * Recorded as theirs with its first message, then run by the worker with a token for this run only. Not when the
     * garden has run its agent too often today: the chat says so.
     */
    #[Route('/chat', name: 'chat_send', methods: ['POST'])]
    #[IsCsrfTokenValid('chat', tokenKey: '_token')]
    public function send(
        Request $request,
        #[CurrentUser]
        User $user,
        MessageBusInterface $bus,
        EntityManagerInterface $entityManager,
        GardenQuotas $gardenQuotas,
        AgentRunTokens $agentRunTokens,
        PreferredLocale $preferredLocale,
        ClockInterface $clock,
        TranslatorInterface $translator,
        #[MapRequestPayload]
        ChatMessageInput $input,
    ): Response {
        $garden = $this->currentGarden->get();
        $turbo = TurboBundle::STREAM_FORMAT === $request->getPreferredFormat();

        if (!$gardenQuotas->consumeAgentRun($garden)) {
            $message = new TranslatableMessage('chat.error.quota');
            if ($turbo) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                return $this->render('chat/stream/error.stream.html.twig', ['message' => $message->trans($translator)]);
            }

            $this->addFlash('warning', $message);

            return $this->redirectToRoute('chat', status: Response::HTTP_SEE_OTHER);
        }

        $conversationId = $this->ownConversationId($user, $garden);
        if (null === $this->conversationRepository->findOneById($conversationId)) {
            $this->conversationRepository->add(Conversation::start($conversationId, $user, $garden, $clock->now()));
            $entityManager->flush();
        }

        $bus->dispatch(new RunAgent(
            $user->getId()->toRfc4122(),
            $garden->getId()->toRfc4122(),
            $conversationId->toRfc4122(),
            trim($input->text),
            $preferredLocale->get()->value,
            $agentRunTokens->issue($user, $garden, $conversationId),
        ));

        // Sent by Turbo: the page shows the message already, the answer comes through Mercure
        if ($turbo) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        return $this->redirectToRoute('chat', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/chat/new', name: 'chat_new', methods: ['POST'])]
    #[IsCsrfTokenValid('chat', tokenKey: '_token')]
    public function new(): RedirectResponse
    {
        $this->chatSession->startNewConversation($this->currentGarden->get());

        return $this->redirectToRoute('chat', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * The conversation of the session in this garden, unless it is recorded as someone else's: a new one then.
     */
    private function ownConversationId(User $user, Garden $garden): UuidV7
    {
        $conversationId = $this->chatSession->getConversationId($garden);
        $conversation = $this->conversationRepository->findOneById($conversationId);

        return null === $conversation || $conversation->isOf($user, $garden) ? $conversationId : $this->chatSession->startNewConversation($garden);
    }
}
