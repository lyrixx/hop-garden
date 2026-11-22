<?php

namespace App\Controller;

use App\Chat\ChatHistory;
use App\Chat\ChatPublisher;
use App\Chat\ChatSession;
use App\Chat\Message\RunAgent;
use App\Chat\Model\ChatEntry;
use App\Chat\Model\ChatMessageInput;
use App\Locale\PreferredLocale;
use App\Plant\PlantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

// Members only: the chat answers on /, which access_control leaves public for the public page to come
#[IsGranted('ROLE_USER')]
final class ChatController extends AbstractController
{
    public function __construct(
        private readonly ChatSession $chatSession,
        private readonly PreferredLocale $preferredLocale,
    ) {
    }

    #[Route('/assistant', name: 'chat', methods: ['GET'])]
    public function index(ChatHistory $chatHistory, PlantRepository $plantRepository): Response
    {
        $conversationId = $this->chatSession->getConversationId();
        $entries = $chatHistory->entries($conversationId);

        // A proposed plant may have been deleted since (demo reset...)
        $plantIds = array_values(array_filter(array_map(static fn (ChatEntry $entry): ?int => $entry->plantId, $entries)));
        $plants = [];
        foreach ([] !== $plantIds ? $plantRepository->findBy(['id' => $plantIds]) : [] as $plant) {
            $plants[$plant->getId()] = $plant;
        }

        return $this->render('chat/index.html.twig', [
            'entries' => $entries,
            'plants' => $plants,
            'topic' => ChatPublisher::topic($conversationId->toRfc4122()),
        ]);
    }

    #[Route('/chat', name: 'chat_send', methods: ['POST'])]
    #[IsCsrfTokenValid('chat', tokenKey: '_token')]
    public function send(
        Request $request,
        MessageBusInterface $bus,
        #[MapRequestPayload]
        ChatMessageInput $input,
    ): Response {
        $bus->dispatch(new RunAgent($this->chatSession->getConversationId()->toRfc4122(), trim($input->text), $this->preferredLocale->get()->value));

        // Sent by Turbo: the page shows the message already, the answer comes through Mercure
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        return $this->redirectToRoute('chat', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/chat/new', name: 'chat_new', methods: ['POST'])]
    #[IsCsrfTokenValid('chat', tokenKey: '_token')]
    public function new(): RedirectResponse
    {
        $this->chatSession->startNewConversation();

        return $this->redirectToRoute('chat', status: Response::HTTP_SEE_OTHER);
    }
}
