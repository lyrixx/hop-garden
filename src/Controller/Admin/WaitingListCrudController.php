<?php

namespace App\Controller\Admin;

use App\Account\AccountMailer;
use App\Account\Entity\User;
use App\Account\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The accounts that verified their email and wait for a decision, the oldest first. Approve or refuse: the member
 * gets an email in their language.
 *
 * @extends AbstractCrudController<User>
 */
#[AdminRoute(path: '/waiting-list', name: 'waiting_list')]
final class WaitingListCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountMailer $accountMailer,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage('admin.waiting_list.singular'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.waiting_list.plural'))
            ->setPageTitle(Crud::PAGE_INDEX, new TranslatableMessage('admin.waiting_list.title'))
            ->setHelp(Crud::PAGE_INDEX, new TranslatableMessage('admin.waiting_list.help'))
            ->setDefaultSort(['createdAt' => 'ASC'])
            ->setSearchFields(['email', 'displayName'])
            ->showEntityActionsInlined()
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('displayName', new TranslatableMessage('admin.user.display_name'));
        yield EmailField::new('email', new TranslatableMessage('admin.user.email'));
        yield DateTimeField::new('createdAt', new TranslatableMessage('admin.user.registered_at'));
        yield DateTimeField::new('emailVerifiedAt', new TranslatableMessage('admin.user.email_verified_at'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, $this->decision('approve', 'fa fa-check')->asSuccessAction())
            ->add(Crud::PAGE_INDEX, $this->decision('refuse', 'fa fa-xmark')->asDangerAction()->askConfirmation(new TranslatableMessage('admin.action.refuse_confirmation')))
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::DETAIL)
        ;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return $this->userRepository->filterWaiting(parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters));
    }

    #[AdminRoute('/{id}/approve', name: 'approve', options: ['methods' => ['POST']])]
    public function approve(User $user, Request $request, ClockInterface $clock): Response
    {
        if (!$this->decide($user, $request, 'approve', static fn () => $user->approve($clock->now()))) {
            return $this->alreadyDecided($user);
        }

        $this->accountMailer->sendAccessApproved($user);
        $this->addFlash('success', new TranslatableMessage('admin.flash.approved', ['name' => $user->getDisplayName()]));

        return $this->redirectToRoute('admin_waiting_list_index');
    }

    #[AdminRoute('/{id}/refuse', name: 'refuse', options: ['methods' => ['POST']])]
    public function refuse(User $user, Request $request): Response
    {
        if (!$this->decide($user, $request, 'refuse', static fn () => $user->refuse())) {
            return $this->alreadyDecided($user);
        }

        $this->accountMailer->sendAccessRefused($user);
        $this->addFlash('success', new TranslatableMessage('admin.flash.refused', ['name' => $user->getDisplayName()]));

        return $this->redirectToRoute('admin_waiting_list_index');
    }

    /**
     * A POST form whose URL carries its CSRF token: EasyAdmin adds none to renderAsForm().
     */
    private function decision(string $name, string $icon): Action
    {
        return Action::new($name, new TranslatableMessage('admin.action.' . $name), $icon)
            ->linkToRoute('admin_waiting_list_' . $name, fn (User $user): array => [
                'id' => $user->getId(),
                'token' => $this->csrfTokenManager->getToken(self::tokenId($name, $user))->getValue(),
            ])
            ->renderAsForm()
            ->displayIf(static fn (User $user): bool => $user->isWaiting())
        ;
    }

    /**
     * False when another tab, or a double click, decided already: one decision, one email. The row stays locked until
     * the decision is committed: the other request waits for it, then sees it.
     *
     * @param \Closure(): void $decision
     */
    private function decide(User $user, Request $request, string $name, \Closure $decision): bool
    {
        if (!$this->isCsrfTokenValid(self::tokenId($name, $user), $request->query->getString('token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        return $this->entityManager->getConnection()->transactional(function () use ($user, $decision): bool {
            $this->userRepository->lock($user);
            if (!$user->isWaiting()) {
                return false;
            }

            $decision();
            $this->entityManager->flush();

            return true;
        });
    }

    private function alreadyDecided(User $user): Response
    {
        $this->addFlash('warning', new TranslatableMessage('admin.flash.already_decided', ['name' => $user->getDisplayName()]));

        return $this->redirectToRoute('admin_waiting_list_index');
    }

    private static function tokenId(string $decision, User $user): string
    {
        return "admin_{$decision}_{$user->getId()}";
    }
}
