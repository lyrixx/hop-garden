<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /**
     * The public page: what Hop Garden takes care of. The chat, on / before, is on /assistant, in the last garden.
     */
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // A link of the time the chat was on / ("Ask the assistant", /?message=...) still opens it, its message typed
        if ($request->query->has('message')) {
            return $this->redirectToRoute('assistant', ['message' => $request->query->getString('message')], Response::HTTP_MOVED_PERMANENTLY);
        }

        return $this->render('home/index.html.twig');
    }
}
