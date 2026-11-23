<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    /**
     * The probe of the orchestrator and of the image (HEALTHCHECK): PHP answers. A probe every few seconds must leave
     * nothing behind: out of every firewall (config/packages/security.yaml), stateless (a use of the session fails the
     * request in debug), and without query (RequestLocaleListener skips it).
     */
    #[Route('/healthz', name: 'healthz', methods: ['GET'], stateless: true)]
    public function __invoke(): Response
    {
        return new Response("OK\n", Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
