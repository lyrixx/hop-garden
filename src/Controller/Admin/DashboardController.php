<?php

namespace App\Controller\Admin;

use App\Account\Entity\User;
use App\Account\UserRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Theme;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The back-office, for ROLE_ADMIN only (access_control): the waiting list first, then every account. In the default
 * theme of EasyAdmin, in the colours of Hop Garden (assets/styles/admin.css).
 */
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function index(): Response
    {
        return $this->redirectToRoute('admin_waiting_list_index');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Hop Garden')
            ->setTranslationDomain('messages')
            ->setTheme(Theme::new()->primaryColor('#5b2a6e'))
            ->disableDarkMode()
        ;
    }

    public function configureAssets(): Assets
    {
        return Assets::new()->addCssFile('styles/admin.css');
    }

    public function configureCrud(): Crud
    {
        return parent::configureCrud()->overrideTemplate('layout', 'admin/layout.html.twig');
    }

    public function configureMenuItems(): iterable
    {
        $waiting = $this->userRepository->countWaiting();

        yield MenuItem::linkTo(WaitingListCrudController::class, new TranslatableMessage('admin.menu.waiting_list'), 'fa fa-hourglass-half')
            ->setBadge($waiting > 0 ? $waiting : false, 'warning')
        ;
        yield MenuItem::linkTo(UserCrudController::class, new TranslatableMessage('admin.menu.users'), 'fa fa-users');
        yield MenuItem::section();
        yield MenuItem::linkToRoute(new TranslatableMessage('admin.menu.back'), 'fa fa-seedling', 'chat');
    }

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        $menu = parent::configureUserMenu($user);

        return $user instanceof User ? $menu->setName($user->getDisplayName()) : $menu;
    }
}
