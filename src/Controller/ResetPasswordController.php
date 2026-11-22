<?php

namespace App\Controller;

use App\Account\AccountMailer;
use App\Account\Form\Model\EmailFormData;
use App\Account\Form\Model\NewPasswordFormData;
use App\Account\Form\NewPasswordType;
use App\Account\Form\PasswordResetRequestType;
use App\Account\PasswordReset;
use App\Account\UserRepository;
use App\Locale\PreferredLocale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * "I forgot my password": the same answer whether the address has an account or not, a link by email, a new password.
 */
final class ResetPasswordController extends AbstractController
{
    private const string TOKEN = 'reset_password_token';

    public function __construct(
        private readonly PasswordReset $passwordReset,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/reset-password', name: 'reset_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request, UserRepository $userRepository, AccountMailer $accountMailer, PreferredLocale $preferredLocale): Response
    {
        $data = new EmailFormData();
        $form = $this->createForm(PasswordResetRequestType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $userRepository->findOneByEmail($data->email);
            if (null !== $user) {
                $token = $this->passwordReset->request($user);
                $this->entityManager->flush();
                $accountMailer->sendPasswordReset($user, $token, $preferredLocale->get());
            }

            return $this->redirectToRoute('reset_password_check_email', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('reset_password/request.html.twig', ['form' => $form]);
    }

    #[Route('/reset-password/check-email', name: 'reset_password_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        return $this->render('reset_password/check_email.html.twig');
    }

    /**
     * The link of the email: its token moves to the session, out of the URL (the history, the Referer of the next page).
     */
    #[Route('/reset-password/reset/{token}', name: 'reset_password_token', methods: ['GET'])]
    public function token(Request $request, #[\SensitiveParameter] string $token): RedirectResponse
    {
        $request->getSession()->set(self::TOKEN, $token);

        return $this->redirectToRoute('reset_password');
    }

    /**
     * No login after: the account may still be on the waiting list.
     */
    #[Route('/reset-password/reset', name: 'reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request): Response
    {
        $token = $request->getSession()->get(self::TOKEN);
        $resetRequest = \is_string($token) ? $this->passwordReset->find($token) : null;
        if (null === $resetRequest) {
            $this->addFlash('warning', new TranslatableMessage('flash.reset_password.invalid_link'));

            return $this->redirectToRoute('reset_password_request', status: Response::HTTP_SEE_OTHER);
        }

        $data = new NewPasswordFormData();
        $form = $this->createForm(NewPasswordType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->passwordReset->reset($resetRequest, $data->plainPassword);
            $this->entityManager->flush();
            $request->getSession()->remove(self::TOKEN);

            $this->addFlash('success', new TranslatableMessage('flash.reset_password.done'));

            return $this->redirectToRoute('login', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('reset_password/reset.html.twig', ['form' => $form]);
    }
}
