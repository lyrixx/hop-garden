<?php

namespace App\Controller\Admin;

use App\Garden\Entity\Garden;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The gardens and their members, read only: what grows in them stays theirs.
 *
 * @extends AbstractCrudController<Garden>
 */
#[AdminRoute(path: '/gardens', name: 'garden')]
final class GardenCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Garden::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage('admin.garden.singular'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.garden.plural'))
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['name', 'location.placeName'])
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', new TranslatableMessage('admin.garden.name'));
        yield TextField::new('location.placeName', new TranslatableMessage('admin.garden.place'));
        yield Field::new('memberships', new TranslatableMessage('admin.garden.members'))
            ->setSortable(false)
            ->setTemplatePath('admin/field/garden_members.html.twig')
        ;
        yield DateTimeField::new('createdAt', new TranslatableMessage('admin.garden.created_at'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
        ;
    }
}
