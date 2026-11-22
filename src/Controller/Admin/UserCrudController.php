<?php

namespace App\Controller\Admin;

use App\Account\Entity\User;
use App\Account\Model\AccessStatus;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Every account, read only: the decisions are taken on the waiting list.
 *
 * @extends AbstractCrudController<User>
 */
#[AdminRoute(path: '/users', name: 'user')]
final class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage('admin.user.singular'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.user.plural'))
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['email', 'displayName'])
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('displayName', new TranslatableMessage('admin.user.display_name'));
        yield EmailField::new('email', new TranslatableMessage('admin.user.email'));
        yield ChoiceField::new('accessStatus', new TranslatableMessage('admin.user.access_status'))
            ->setChoices(AccessStatus::cases())
            ->renderAsBadges([AccessStatus::Pending->value => 'warning', AccessStatus::Approved->value => 'success', AccessStatus::Refused->value => 'danger'])
        ;
        yield BooleanField::new('admin', new TranslatableMessage('admin.user.admin'))->renderAsSwitch(false);
        yield DateTimeField::new('createdAt', new TranslatableMessage('admin.user.registered_at'));
        yield DateTimeField::new('emailVerifiedAt', new TranslatableMessage('admin.user.email_verified_at'));
        yield DateTimeField::new('approvedAt', new TranslatableMessage('admin.user.approved_at'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
        ;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(ChoiceFilter::new('accessStatus', new TranslatableMessage('admin.user.access_status'))->setTranslatableChoices(AccessStatus::cases()));
    }
}
