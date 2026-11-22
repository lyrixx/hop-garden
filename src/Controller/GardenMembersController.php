<?php

namespace App\Controller;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Invitation;
use App\Garden\Entity\Membership;
use App\Garden\Form\InvitationType;
use App\Garden\Form\Model\InvitationFormData;
use App\Garden\GardenQuotas;
use App\Garden\InvitationRepository;
use App\Garden\Invitations;
use App\Garden\Model\InvitationLink;
use App\Garden\Security\GardenVoter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The members of a garden, each of whom may leave it (GARDEN_VIEW), and its invitations: its owner invites by email,
 * sends an invitation again or shows a new link of it, revokes it, and removes a member (GARDEN_MANAGE). An invitation or
 * a member is found in the garden of the URL only (MapEntity): another one answers 404, as a garden of others does.
 */
#[Route('/gardens/{id}', requirements: ['id' => Requirement::DIGITS])]
final class GardenMembersController extends AbstractController
{
    /**
     * The link of an invitation just sent or renewed, shown once on the page of the members: the database keeps its
     * hash only.
     */
    private const string SHOWN_LINK = 'garden_invitation_link';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Invitations $invitations,
        private readonly InvitationRepository $invitationRepository,
        private readonly GardenQuotas $gardenQuotas,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/members', name: 'garden_members', methods: ['GET'])]
    #[IsGranted(GardenVoter::VIEW, 'garden', statusCode: Response::HTTP_NOT_FOUND)]
    public function index(Request $request, Garden $garden): Response
    {
        return $this->renderMembers($request, $garden, $this->isGranted(GardenVoter::MANAGE, $garden) ? $this->invitationForm($garden) : null);
    }

    /**
     * Sent at once, and its link shown: the address may receive it, or not, the owner may send it another way.
     */
    #[Route('/invitations', name: 'garden_invite', methods: ['POST'])]
    #[IsGranted(GardenVoter::MANAGE, 'garden', statusCode: Response::HTTP_NOT_FOUND)]
    public function invite(Request $request, Garden $garden, #[CurrentUser] User $user): Response
    {
        $data = new InvitationFormData($garden);
        $form = $this->invitationForm($garden, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            // Shown again, a 422: Turbo Drive renders it in place
            return $this->renderMembers($request, $garden, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$this->gardenQuotas->consumeInvitation($garden)) {
            return $this->quotaReached($garden);
        }

        $link = $this->invitations->invite($garden, $data->email, $user);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // The same address invited twice at once (a double click): the first one sends it
            $this->logger->warning('The address was invited twice at once.', ['exception' => $e]);

            return $this->redirectToRoute('garden_members', ['id' => $garden->getId()], Response::HTTP_SEE_OTHER);
        }

        $this->invitations->sendEmail($link);
        $this->showLink($request, $link);
        $this->addFlash('success', new TranslatableMessage('flash.invitation.sent', ['email' => $link->invitation->getEmail()]));

        return $this->redirectToRoute('garden_members', ['id' => $garden->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * By email again, with a new link: the one sent before stops working.
     */
    #[Route('/invitations/{invitationId}/resend', name: 'garden_invitation_resend', requirements: ['invitationId' => Requirement::UUID_V7], methods: ['POST'])]
    #[IsGranted(GardenVoter::MANAGE, 'garden', statusCode: Response::HTTP_NOT_FOUND)]
    #[IsCsrfTokenValid('garden_invitation', tokenKey: '_token')]
    public function resend(
        Request $request,
        Garden $garden,
        #[MapEntity(mapping: ['invitationId' => 'id', 'id' => 'garden'])]
        Invitation $invitation,
    ): Response {
        if (!$this->gardenQuotas->consumeInvitation($garden)) {
            return $this->quotaReached($garden);
        }

        $link = $this->whileOpen($invitation, fn (): InvitationLink => $this->invitations->renew($invitation));
        if (null !== $link) {
            $this->invitations->sendEmail($link);
            $this->showLink($request, $link);
            $this->addFlash('success', new TranslatableMessage('flash.invitation.resent', ['email' => $invitation->getEmail()]));
        }

        return $this->redirectToRoute('garden_members', ['id' => $garden->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * A new link, without email, copied at once where the browser lets the page: the one sent before stops working.
     */
    #[Route('/invitations/{invitationId}/link', name: 'garden_invitation_link', requirements: ['invitationId' => Requirement::UUID_V7], methods: ['POST'])]
    #[IsGranted(GardenVoter::MANAGE, 'garden', statusCode: Response::HTTP_NOT_FOUND)]
    #[IsCsrfTokenValid('garden_invitation', tokenKey: '_token')]
    public function link(
        Request $request,
        Garden $garden,
        #[MapEntity(mapping: ['invitationId' => 'id', 'id' => 'garden'])]
        Invitation $invitation,
    ): Response {
        $link = $this->whileOpen($invitation, fn (): InvitationLink => $this->invitations->renew($invitation));
        if (null !== $link) {
            $this->showLink($request, $link, copy: true);
            $this->addFlash('success', new TranslatableMessage('flash.invitation.new_link', ['email' => $invitation->getEmail()]));
        }

        return $this->redirectToRoute('garden_members', ['id' => $garden->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/invitations/{invitationId}/revoke', name: 'garden_invitation_revoke', requirements: ['invitationId' => Requirement::UUID_V7], methods: ['POST'])]
    #[IsGranted(GardenVoter::MANAGE, 'garden', statusCode: Response::HTTP_NOT_FOUND)]
    #[IsCsrfTokenValid('garden_invitation', tokenKey: '_token')]
    public function revoke(
        Garden $garden,
        #[MapEntity(mapping: ['invitationId' => 'id', 'id' => 'garden'])]
        Invitation $invitation,
    ): Response {
        $revoked = $this->whileOpen($invitation, function () use ($invitation): true {
            $invitation->revoke($this->clock->now());

            return true;
        });
        if (null !== $revoked) {
            $this->addFlash('success', new TranslatableMessage('flash.invitation.revoked', ['email' => $invitation->getEmail()]));
        }

        return $this->redirectToRoute('garden_members', ['id' => $garden->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * By its owner, never themselves: they leave it.
     */
    #[Route('/members/{membershipId}/remove', name: 'garden_member_remove', requirements: ['membershipId' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(GardenVoter::MANAGE, 'garden', statusCode: Response::HTTP_NOT_FOUND)]
    #[IsCsrfTokenValid('garden_member', tokenKey: '_token')]
    public function remove(
        Garden $garden,
        #[MapEntity(mapping: ['membershipId' => 'id', 'id' => 'garden'])]
        Membership $membership,
    ): Response {
        if ($membership->isOwner()) {
            throw $this->createNotFoundException('Its owner leaves a garden, never removed from it.');
        }

        $name = $membership->getUser()->getDisplayName();
        $garden->remove($membership);
        $this->entityManager->flush();

        $this->addFlash('success', new TranslatableMessage('flash.member.removed', ['name' => $name, 'garden' => $garden->getName()]));

        return $this->redirectToRoute('garden_members', ['id' => $garden->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Any member, its owner too: the oldest of the others takes it over (Garden::leave()). Its only member does not
     * leave it: it goes with their account.
     */
    #[Route('/leave', name: 'garden_leave', methods: ['POST'])]
    #[IsGranted(GardenVoter::VIEW, 'garden', statusCode: Response::HTTP_NOT_FOUND)]
    #[IsCsrfTokenValid('garden_leave', tokenKey: '_token')]
    public function leave(Garden $garden, #[CurrentUser] User $user): Response
    {
        if ([$user] === $garden->getMembers()) {
            $this->addFlash('warning', new TranslatableMessage('flash.member.only_member', ['garden' => $garden->getName()]));

            return $this->redirectToRoute('garden_members', ['id' => $garden->getId()], Response::HTTP_SEE_OTHER);
        }

        $garden->leave($user);
        $this->entityManager->flush();

        // Their current garden is another one now, or none: the wizard
        $this->addFlash('success', new TranslatableMessage('flash.member.left', ['garden' => $garden->getName()]));

        return $this->redirectToRoute('garden', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * @param ?FormInterface<InvitationFormData> $form the invitation form, for its owner only
     */
    private function renderMembers(Request $request, Garden $garden, ?FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        /** @var ?array{invitation: string, url: string, copy: bool} $shownLink */
        $shownLink = $request->getSession()->remove(self::SHOWN_LINK);

        return $this->render('gardens/members.html.twig', [
            'garden' => $garden,
            'form' => $form,
            'invitations' => null !== $form ? $this->invitationRepository->findOpenOfGarden($garden) : [],
            'shown_link' => $shownLink,
            'now' => $this->clock->now(),
        ], new Response(status: $status));
    }

    /**
     * @return FormInterface<InvitationFormData>
     */
    private function invitationForm(Garden $garden, ?InvitationFormData $data = null): FormInterface
    {
        return $this->createForm(InvitationType::class, $data ?? new InvitationFormData($garden), [
            'action' => $this->generateUrl('garden_invite', ['id' => $garden->getId()]),
        ]);
    }

    private function showLink(Request $request, InvitationLink $link, bool $copy = false): void
    {
        $request->getSession()->set(self::SHOWN_LINK, [
            'invitation' => $link->invitation->getId()->toRfc4122(),
            'url' => $this->generateUrl('invitation', ['token' => $link->token], UrlGeneratorInterface::ABSOLUTE_URL),
            'copy' => $copy,
        ]);
    }

    /**
     * Locked until committed: an acceptance at the same moment waits for the change, or the change for it.
     *
     * @template T
     *
     * @param \Closure(): T $change
     *
     * @return ?T null when it was accepted or revoked meanwhile: the page says so
     */
    private function whileOpen(Invitation $invitation, \Closure $change): mixed
    {
        $result = $this->entityManager->wrapInTransaction(function () use ($invitation, $change): mixed {
            $this->invitationRepository->lock($invitation);

            return $invitation->isOpen() ? $change() : null;
        });

        if (null === $result) {
            $this->addFlash('warning', new TranslatableMessage('flash.invitation.closed', ['email' => $invitation->getEmail()]));
        }

        return $result;
    }

    private function quotaReached(Garden $garden): Response
    {
        $this->addFlash('warning', new TranslatableMessage('flash.quota.invitations'));

        return $this->redirectToRoute('garden_members', ['id' => $garden->getId()], Response::HTTP_SEE_OTHER);
    }
}
