<?php

namespace App\Controller;

use App\Garden\LastGarden;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The paths of before the gardens were in the URLs (/assistant, /garden, /plants...), in the links already sent and
 * the bookmarks: the same page in the last garden of the member, its query string kept (/assistant?message=). A member
 * without garden yet makes one first. /assistant is where a login leads (config/packages/security.yaml) and where
 * "Open my garden" opens. The routes: config/routes.yaml.
 */
final class LastGardenController extends AbstractController
{
    public function __invoke(Request $request, string $route, LastGarden $lastGarden): RedirectResponse
    {
        $garden = $lastGarden->find();
        if (null === $garden) {
            return $this->redirectToRoute('garden_create', status: Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute($route, [...$request->query->all(), 'garden' => $garden->getSlug()], Response::HTTP_FOUND);
    }
}
