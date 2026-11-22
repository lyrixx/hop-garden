<?php

namespace App\Controller;

use App\Account\AccountMailer;
use App\Account\Entity\User;
use App\Account\Form\Model\RegistrationFormData;
use App\Account\Form\RegistrationType;
use App\Account\UserRepository;
use App\Locale\Model\SupportedLocale;
use App\Locale\PreferredLocale;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\ExpiredSignedUriException;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The waiting list: register, verify the email, then wait for the administrator (App\Controller\Admin\).
 */
final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountMailer $accountMailer,
        private readonly PreferredLocale $preferredLocale,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The same answer whether the address has an account or not: the email tells its owner.
     */
    #[Route('/register', name: 'register', methods: ['GET', 'POST'])]
    public function register(Request $request, PasswordHasherFactoryInterface $passwordHasherFactory): Response
    {
        if ($this->isGranted('ROLE_USER')) {
            return $this->redirectToRoute('chat');
        }

        $data = new RegistrationFormData();
        $form = $this->createForm(RegistrationType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Before the lookup: the answer takes as long whether the address has an account or not
            $passwordHash = $passwordHasherFactory->getPasswordHasher(User::class)->hash($data->plainPassword);
            $locale = $this->preferredLocale->get();

            $owner = $this->userRepository->findOneByEmail($data->email);
            if (null !== $owner) {
                $this->accountMailer->sendAddressInUse($owner, $locale);
            } else {
                // The language chosen as a visitor; null otherwise, and the browser is offered after the login
                $this->registerNew($data->toUser($passwordHash, $this->preferredLocale->isChosen() ? $locale : null, $this->clock->now()), $locale);
            }

            return $this->redirectToRoute('register_check_email', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('registration/register.html.twig', ['form' => $form]);
    }

    #[Route('/register/check-email', name: 'register_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        return $this->render('registration/check_email.html.twig');
    }

    /**
     * The signed link of the email (App\Account\SignedLinks). No login: the administrator decides first.
     */
    #[Route('/register/verify', name: 'register_verify', methods: ['GET'])]
    public function verify(Request $request, UriSigner $uriSigner): Response
    {
        try {
            $uriSigner->verify($request);
        } catch (ExpiredSignedUriException) {
            // A password reset proves the address too, and sets a password its owner knows
            $this->addFlash('warning', new TranslatableMessage('flash.registration.link_expired'));

            return $this->redirectToRoute('reset_password_request', status: Response::HTTP_SEE_OTHER);
        } catch (SignedUriException $e) {
            throw $this->createNotFoundException('This link was not signed by Hop Garden.', $e);
        }

        $user = $this->userRepository->find($request->query->getInt('id')) ?? throw $this->createNotFoundException('This account was deleted.');
        $user->verifyEmail($this->clock->now());
        $this->entityManager->flush();

        return $this->redirectToRoute('register_waiting_list', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/register/waiting-list', name: 'register_waiting_list', methods: ['GET'])]
    public function waitingList(): Response
    {
        return $this->render('registration/waiting_list.html.twig');
    }

    private function registerNew(User $user, SupportedLocale $locale): void
    {
        $this->userRepository->add($user);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // The same address registered twice at once (a double click): the first one sends the link
            $this->logger->warning('The address was registered twice at once.', ['exception' => $e]);

            return;
        }

        $this->accountMailer->sendEmailVerification($user, $locale);
    }
}
