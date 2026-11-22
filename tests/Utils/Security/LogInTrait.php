<?php

namespace App\Tests\Utils\Security;

use App\Account\Entity\User;
use App\Factory\UserFactory;
use App\Garden\Entity\Garden;
use App\Garden\GardenRepository;
use App\Story\DefaultGardenStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Every page but the ones of the login needs an approved member (config/packages/security.yaml), and the pages of a
 * garden a garden of theirs: the client is a member of the garden of the test, where the factories put what they make
 * (DefaultGardenStory), unless told.
 */
trait LogInTrait
{
    /**
     * By default the owner of the garden of the test. Another member joins it first, unless they have a garden already.
     */
    private function logIn(KernelBrowser $client, ?User $member = null): User
    {
        $member ??= $this->gardenOfTheTest()->getOwner();
        if ([] === self::getContainer()->get(GardenRepository::class)->findOfMember($member)) {
            $this->joinTheGardenOfTheTest($member);
        }

        $client->loginUser($member);

        return $member;
    }

    private function joinTheGardenOfTheTest(User $member): void
    {
        // Of the entity manager of the current kernel: a request may have rebooted it
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $garden = $entityManager->find(Garden::class, $this->gardenOfTheTest()->getId()) ?? self::fail('The garden of the test is gone.');
        $garden->join($entityManager->find(User::class, $member->getId()) ?? self::fail('The member is gone.'), $garden->getCreatedAt()->modify('+1 day'));
        $entityManager->flush();
    }

    /**
     * An approved member who made no garden yet: the pages of the garden send them to the wizard.
     */
    private function logInWithoutGarden(KernelBrowser $client, ?User $member = null): User
    {
        $member ??= UserFactory::new()->approved()->create();
        $client->loginUser($member);

        return $member;
    }

    /**
     * The garden the factories put what they make in, unless told: the current one of the member logged in by default.
     */
    private function gardenOfTheTest(): Garden
    {
        $garden = DefaultGardenStory::get('garden');
        self::assertInstanceOf(Garden::class, $garden);

        return $garden;
    }

    /**
     * As a browser does: the firewall checks the password, then App\Account\Security\UserChecker the access.
     */
    private function logInWithTheForm(KernelBrowser $client, string $email, string $password = UserFactory::PASSWORD, bool $rememberMe = false): void
    {
        $form = $client->request('GET', '/login')->filter('main form')->form();

        $client->submit($form, ['_username' => $email, '_password' => $password, '_remember_me' => $rememberMe]);
    }

    /**
     * The failed logins count in a cache pool, which outlives the test: login_throttling would stop the next ones.
     */
    private function forgetTheFailedLogins(): void
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
    }
}
