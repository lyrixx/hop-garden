<?php

namespace App\Controller;

use App\Account\AccountMailer;
use App\Account\Entity\User;
use App\Account\Form\Model\RegistrationFormData;
use App\Account\Form\RegistrationType;
use App\Account\UserRepository;
use App\Garden\Entity\Invitation;
use App\Garden\InvitationRepository;
use App\Garden\Invitations;
use App\Garden\Model\InvitationStatus;
use App\Locale\PreferredLocale;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The link of an invitation, its token only: whoever has it joins the garden, with their account, or a new one that
 * comes in without the waiting list. The new account has its email verified when it is the address invited, and is
 * logged in at once; with another address, it verifies it first. An invitation that no longer works says why, without
 * naming its garden nor who sent it.
 */
final class InvitationController extends AbstractController
{
    use TargetPathTrait;

    /**
     * 32 random bytes in base64url (App\Garden\Invitations).
     */
    public const string TOKEN_REQUIREMENT = '[A-Za-z0-9_-]{43}';

    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly Invitations $invitations,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Its garden and who invites, then: join it, logged in; or create an account, or log in, and come back here.
     */
    #[Route('/invitations/{token}', name: 'invitation', requirements: ['token' => self::TOKEN_REQUIREMENT], methods: ['GET'])]
    public function show(Request $request, #[\SensitiveParameter] string $token, #[CurrentUser] ?User $user): Response
    {
        $invitation = $this->invitationRepository->findOneByToken($token);
        if (null === $invitation) {
            return $this->unknown();
        }
        if (null !== $response = $this->unavailable($invitation, $user)) {
            return $response;
        }

        if (null === $user) {
            // The login leads back here
            $this->saveTargetPath($request->getSession(), 'main', $this->generateUrl('invitation', ['token' => $token]));
        }

        return $this->render('invitation/show.html.twig', [
            'invitation' => $invitation,
            'token' => $token,
            'sent_to_them' => null !== $user && $invitation->isSentTo($user),
        ]);
    }

    /**
     * The registration, its email the one invited. The same answer as /register for an address that has an account: the
     * email tells its owner.
     */
    #[Route('/invitations/{token}/register', name: 'invitation_register', requirements: ['token' => self::TOKEN_REQUIREMENT], methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        #[\SensitiveParameter]
        string $token,
        UserRepository $userRepository,
        AccountMailer $accountMailer,
        PreferredLocale $preferredLocale,
        PasswordHasherFactoryInterface $passwordHasherFactory,
        Security $security,
    ): Response {
        if ($this->isGranted('ROLE_USER')) {
            return $this->redirectToRoute('invitation', ['token' => $token]);
        }

        $invitation = $this->invitationRepository->findOneByToken($token);
        if (null === $invitation) {
            return $this->unknown();
        }
        if (null !== $response = $this->unavailable($invitation, null)) {
            return $response;
        }

        $data = new RegistrationFormData();
        $data->email = $invitation->getEmail();
        $form = $this->createForm(RegistrationType::class, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('invitation/register.html.twig', ['invitation' => $invitation, 'form' => $form]);
        }

        // Before the lookup: the answer takes as long whether the address has an account or not
        $passwordHash = $passwordHasherFactory->getPasswordHasher(User::class)->hash($data->plainPassword);
        $locale = $preferredLocale->get();

        $owner = $userRepository->findOneByEmail($data->email);
        if (null !== $owner) {
            $accountMailer->sendAddressInUse($owner, $locale);

            return $this->redirectToRoute('register_check_email', status: Response::HTTP_SEE_OTHER);
        }

        $now = $this->clock->now();
        $user = $data->toUser($passwordHash, $preferredLocale->isChosen() ? $locale : null, $now);
        $sentToThem = $invitation->isSentTo($user);
        if ($sentToThem) {
            // The link came to this address
            $user->verifyEmail($now);
        }
        $user->admitByInvitation($now);

        try {
            $accepted = $this->entityManager->wrapInTransaction(function () use ($invitation, $user, $userRepository): bool {
                if (!$this->invitations->accept($invitation, $user)) {
                    return false;
                }
                $userRepository->add($user);

                return true;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same address registered twice at once (a double click): the first one joins
            $this->logger->warning('The address was registered twice at once.', ['exception' => $e]);

            return $this->redirectToRoute('register_check_email', status: Response::HTTP_SEE_OTHER);
        }
        if (!$accepted) {
            // Accepted by somebody else meanwhile: no account, its page says so
            return $this->redirectToRoute('invitation', ['token' => $token], Response::HTTP_SEE_OTHER);
        }

        $this->removeTargetPath($request->getSession(), 'main');
        if (!$sentToThem) {
            $accountMailer->sendEmailVerification($user, $locale);
            $this->addFlash('success', new TranslatableMessage('flash.invitation.verify_first', ['garden' => $invitation->getGarden()->getName()]));

            return $this->redirectToRoute('register_check_email', status: Response::HTTP_SEE_OTHER);
        }

        $security->login($user, 'form_login', 'main');

        return $this->welcome($invitation);
    }

    #[Route('/invitations/{token}/accept', name: 'invitation_accept', requirements: ['token' => self::TOKEN_REQUIREMENT], methods: ['POST'])]
    #[IsCsrfTokenValid('invitation_accept', tokenKey: '_token')]
    public function accept(Request $request, #[\SensitiveParameter] string $token, #[CurrentUser] User $user): Response
    {
        $invitation = $this->invitationRepository->findOneByToken($token);
        if (null === $invitation) {
            return $this->unknown();
        }

        try {
            $accepted = $this->entityManager->wrapInTransaction(fn (): bool => $this->invitations->accept($invitation, $user));
        } catch (UniqueConstraintViolationException $e) {
            // Two invitations of the same garden accepted at once by the same member: the first one joins
            $this->logger->warning('A member joined the same garden twice at once.', ['exception' => $e]);
            $accepted = false;
        }
        if (!$accepted) {
            // Its page says why: a member already, or it no longer works
            return $this->redirectToRoute('invitation', ['token' => $token], Response::HTTP_SEE_OTHER);
        }

        $this->removeTargetPath($request->getSession(), 'main');

        return $this->welcome($invitation);
    }

    /**
     * In the garden they joined, under its URL.
     */
    private function welcome(Invitation $invitation): Response
    {
        $garden = $invitation->getGarden();
        $this->addFlash('success', new TranslatableMessage('flash.invitation.joined', ['garden' => $garden->getName()]));

        return $this->redirectToRoute('garden', ['garden' => $garden->getSlug()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Null while it is pending and they are no member yet. A member already is shown their garden; the others, why it no
     * longer works, nothing more.
     */
    private function unavailable(Invitation $invitation, ?User $user): ?Response
    {
        $garden = $invitation->getGarden();
        if (null !== $user && $garden->isMember($user)) {
            return $this->render('invitation/member.html.twig', ['garden' => $garden]);
        }

        $status = $invitation->statusAt($this->clock->now());

        return InvitationStatus::Pending === $status ? null : $this->render('invitation/unavailable.html.twig', ['status' => $status->value], new Response(status: Response::HTTP_GONE));
    }

    /**
     * A token that never was, or the one of a garden deleted since (its invitations go with it): the same page.
     */
    private function unknown(): Response
    {
        return $this->render('invitation/unavailable.html.twig', ['status' => 'unknown'], new Response(status: Response::HTTP_NOT_FOUND));
    }
}
