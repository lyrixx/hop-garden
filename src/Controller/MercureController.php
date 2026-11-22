<?php

namespace App\Controller;

use App\Account\Entity\User;
use App\Mercure\SubscriberCookie;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class MercureController extends AbstractController
{
    /**
     * The subscriber cookie lasts an hour (mercure.default_cookie_lifetime), and the hub closes a stream when it
     * expires: a page left open renews it before then (assets/controllers/mercure_cookie_controller.js).
     */
    #[Route('/mercure/cookie', name: 'mercure_cookie', methods: ['POST'])]
    public function renewCookie(Request $request, #[CurrentUser] User $user, SubscriberCookie $subscriberCookie): Response
    {
        $subscriberCookie->set($request, $user);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
