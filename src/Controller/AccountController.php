<?php

namespace App\Controller;

use App\Account\AccountDeletion;
use App\Account\AccountMailer;
use App\Account\Entity\User;
use App\Account\Form\AccountDeletionType;
use App\Account\Form\EmailChangeType;
use App\Account\Form\Model\AccountDeletionFormData;
use App\Account\Form\Model\EmailChangeFormData;
use App\Account\Form\Model\PasswordChangeFormData;
use App\Account\Form\Model\ProfileFormData;
use App\Account\Form\PasswordChangeType;
use App\Account\Form\ProfileType;
use App\Account\PasswordResetRequestRepository;
use App\Account\UserRepository;
use App\Garden\PlantingPhotoStorage;
use App\Locale\PreferredLocale;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Exception\ExpiredSignedUriException;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The account of the member logged in: one page, a form per change, each posted to its own route.
 */
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PreferredLocale $preferredLocale,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The name and the language.
     */
    #[Route('/account', name: 'account', methods: ['GET', 'POST'])]
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(ProfileType::class, ProfileFormData::fromUser($user, $this->preferredLocale->get()));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $user->rename(trim($data->displayName));
            $this->preferredLocale->set($data->preferredLocale ?? throw new \LogicException('A language is required: the form validated one.'));
            $this->entityManager->flush();

            return $this->back(new TranslatableMessage('flash.account.saved'));
        }

        return $this->renderAccount($user, profile: $form);
    }

    /**
     * Nothing changes before the new address is proved, by the link sent to it. The same answer when another account
     * has it: the email tells its owner.
     */
    #[Route('/account/email', name: 'account_email', methods: ['POST'])]
    public function email(Request $request, #[CurrentUser] User $user, UserRepository $userRepository, AccountMailer $accountMailer): Response
    {
        $data = new EmailChangeFormData($user->getEmail());
        $form = $this->createForm(EmailChangeType::class, $data, ['action' => $this->generateUrl('account_email')]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderAccount($user, email: $form);
        }

        $owner = $userRepository->findOneByEmail($data->newEmail);
        if (null !== $owner) {
            $accountMailer->sendAddressInUse($owner, $this->preferredLocale->get());
        } else {
            $accountMailer->sendEmailChange($user, $data->newEmail, $this->preferredLocale->get());
        }

        return $this->back(new TranslatableMessage('flash.account.email_sent', ['email' => User::normalizeEmail($data->newEmail)]));
    }

    /**
     * The signed link sent to the new address (App\Account\SignedLinks).
     */
    #[Route('/account/email/confirm', name: 'account_email_confirm', methods: ['GET'])]
    public function confirmEmail(Request $request, #[CurrentUser] User $user, UriSigner $uriSigner, UserRepository $userRepository): Response
    {
        try {
            $uriSigner->verify($request);
        } catch (ExpiredSignedUriException) {
            return $this->back(new TranslatableMessage('flash.account.email_link_expired'), 'warning');
        } catch (SignedUriException $e) {
            throw $this->createNotFoundException('This link was not signed by Hop Garden.', $e);
        }

        // Used already, or opened in the session of another account
        if ($request->query->getString('from') !== $user->getEmail()) {
            return $this->back(new TranslatableMessage('flash.account.email_link_used'), 'warning');
        }

        $email = $request->query->getString('email');
        if (null !== $userRepository->findOneByEmail($email)) {
            return $this->back(new TranslatableMessage('flash.account.email_taken'), 'warning');
        }

        $user->changeEmail($email, $this->clock->now());

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Registered meanwhile
            return $this->back(new TranslatableMessage('flash.account.email_taken'), 'warning');
        }

        // The email identifies the session: logged in again under the new one
        $this->security->login($user, 'form_login');

        return $this->back(new TranslatableMessage('flash.account.email_changed', ['email' => $email]));
    }

    /**
     * The other sessions end: Symfony compares a checksum of the password on each request. This one starts again.
     */
    #[Route('/account/password', name: 'account_password', methods: ['POST'])]
    public function password(Request $request, #[CurrentUser] User $user, PasswordHasherFactoryInterface $passwordHasherFactory, PasswordResetRequestRepository $passwordResetRequestRepository): Response
    {
        $data = new PasswordChangeFormData();
        $form = $this->createForm(PasswordChangeType::class, $data, ['action' => $this->generateUrl('account_password')]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderAccount($user, password: $form);
        }

        $user->changePassword($passwordHasherFactory->getPasswordHasher(User::class)->hash($data->newPassword));
        $passwordResetRequestRepository->removeAllOf($user);
        $this->entityManager->flush();

        $this->security->login($user, 'form_login');

        return $this->back(new TranslatableMessage('flash.account.password_changed'));
    }

    /**
     * Their gardens of their own go with it, their shared gardens stay with the others: see AccountDeletion.
     */
    #[Route('/account/delete', name: 'account_delete', methods: ['POST'])]
    public function delete(Request $request, #[CurrentUser] User $user, AccountDeletion $accountDeletion, PlantingPhotoStorage $photoStorage): Response
    {
        $form = $this->createForm(AccountDeletionType::class, new AccountDeletionFormData(), ['action' => $this->generateUrl('account_delete')]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderAccount($user, deletion: $form);
        }

        // The messages of the conversations, in the table of the message store, go in the same transaction
        $photos = $this->entityManager->wrapInTransaction(function () use ($accountDeletion, $user): array {
            $photos = $accountDeletion->delete($user);
            $this->entityManager->flush();

            return $photos;
        });
        foreach ($photos as $photo) {
            $photoStorage->remove($photo);
        }

        // Its response clears the cookies, the remember me one too
        $response = $this->security->logout(validateCsrfToken: false) ?? $this->redirectToRoute('login');
        $this->addFlash('success', new TranslatableMessage('flash.account.deleted'));

        return $response;
    }

    /**
     * @param FormInterface<ProfileFormData>|null         $profile
     * @param FormInterface<EmailChangeFormData>|null     $email
     * @param FormInterface<PasswordChangeFormData>|null  $password
     * @param FormInterface<AccountDeletionFormData>|null $deletion
     */
    private function renderAccount(User $user, ?FormInterface $profile = null, ?FormInterface $email = null, ?FormInterface $password = null, ?FormInterface $deletion = null): Response
    {
        return $this->render('account/index.html.twig', [
            'user' => $user,
            'profile_form' => $profile ?? $this->createForm(ProfileType::class, ProfileFormData::fromUser($user, $this->preferredLocale->get())),
            'email_form' => $email ?? $this->createForm(EmailChangeType::class, new EmailChangeFormData($user->getEmail()), ['action' => $this->generateUrl('account_email')]),
            'password_form' => $password ?? $this->createForm(PasswordChangeType::class, new PasswordChangeFormData(), ['action' => $this->generateUrl('account_password')]),
            'deletion_form' => $deletion ?? $this->createForm(AccountDeletionType::class, new AccountDeletionFormData(), ['action' => $this->generateUrl('account_delete')]),
        ]);
    }

    private function back(TranslatableMessage $message, string $type = 'success'): RedirectResponse
    {
        $this->addFlash($type, $message);

        return $this->redirectToRoute('account', status: Response::HTTP_SEE_OTHER);
    }
}
