<?php

namespace App\Tests\Utils\Security;

use App\Account\Entity\User;
use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Every page but the ones of the login needs an approved member (config/packages/security.yaml): the client is one.
 */
trait LogInTrait
{
    private function logIn(KernelBrowser $client, ?User $member = null): User
    {
        $member ??= UserFactory::new()->approved()->create();
        $client->loginUser($member);

        return $member;
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
