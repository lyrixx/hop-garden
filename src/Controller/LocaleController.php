<?php

namespace App\Controller;

use App\Locale\Model\LocaleChoice;
use App\Locale\PreferredLocale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

final class LocaleController extends AbstractController
{
    /**
     * The language of the interface, from the banner or the footer: the same page again, in that language. Once chosen,
     * the banner does not come back.
     */
    #[Route('/locale', name: 'locale', methods: ['POST'])]
    #[IsCsrfTokenValid('locale', tokenKey: '_token')]
    public function choose(
        PreferredLocale $preferredLocale,
        EntityManagerInterface $entityManager,
        #[MapRequestPayload]
        LocaleChoice $choice,
    ): RedirectResponse {
        $preferredLocale->set($choice->locale);
        $entityManager->flush();

        return $this->redirect($choice->returnTo, Response::HTTP_SEE_OTHER);
    }
}
