<?php

namespace App\Tests\Functional\Account;

use App\Account\Entity\User;
use App\Account\Model\AccessStatus;
use App\Account\UserRepository;
use App\Factory\UserFactory;
use App\Locale\Model\SupportedLocale;
use App\Locale\UserPreferredLocale;
use App\Tests\Utils\Mailer\SentEmailsTrait;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The waiting list: register, verify the email, wait. The administrator decides: see WaitingListTest.
 */
final class RegistrationTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use LogInTrait;
    use SentEmailsTrait;

    private const string PASSWORD = 'a long enough password';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testRegisterVerifyThenWait(): void
    {
        self::mockTime('2026-11-22 10:00');

        $this->register('Alice@Example.com', 'Alice ');

        self::assertResponseRedirects('/register/check-email', 303);
        self::assertEmailCount(1);
        $email = self::lastEmail();
        self::assertEmailAddressContains($email, 'to', 'alice@example.com');
        self::assertEmailSubjectContains($email, 'Verify your email for Hop Garden');
        self::assertEmailHtmlBodyContains($email, 'Hello Alice,');
        self::assertEmailHtmlBodyContains($email, 'This link works for 48 hours.');
        $user = $this->user('alice@example.com');
        self::assertSame('Alice', $user->getDisplayName());
        self::assertFalse($user->isEmailVerified());
        self::assertNull($user->getPreferredLocale(), 'Not chosen: the browser is offered after the login.');
        self::assertTrue(password_verify(self::PASSWORD, $user->getPassword()), 'Hashed.');

        $this->client->followRedirect();
        self::assertSelectorTextSame('h1', 'Check your inbox');

        self::mockTime('2026-11-23 18:00');
        $this->client->request('GET', self::linkOf($email));

        self::assertResponseRedirects('/register/waiting-list', 303);
        $this->client->followRedirect();
        self::assertSelectorTextSame('h1', 'You are on the waiting list');
        $user = $this->user('alice@example.com');
        self::assertSame('2026-11-23 18:00', $user->getEmailVerifiedAt()?->format('Y-m-d H:i'));
        self::assertTrue($user->isWaiting());
        self::assertSame(AccessStatus::Pending, $user->getAccessStatus());

        $this->forgetTheFailedLogins();
        $this->logInWithTheForm($this->client, 'alice@example.com', self::PASSWORD);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main .warning', 'Your account is on the waiting list', 'Not in before the administrator says so.');
    }

    public function testAnAddressWithAnAccountGetsTheSameAnswerAndItsOwnerAnEmail(): void
    {
        $owner = UserFactory::new()->approved()->create(['email' => 'alice@example.com', 'displayName' => 'Alice', 'preferredLocale' => SupportedLocale::French]);
        $passwordHash = $owner->getPassword();

        $this->register('ALICE@example.com', 'Mallory');

        self::assertResponseRedirects('/register/check-email', 303, 'As for a new address.');
        self::assertEmailCount(1);
        $email = self::lastEmail();
        self::assertEmailAddressContains($email, 'to', 'alice@example.com');
        self::assertEmailSubjectContains($email, 'Vous avez déjà un compte Hop Garden', 'In the language of its owner.');
        self::assertEmailHtmlBodyContains($email, 'Bonjour Alice,');
        self::assertEmailHtmlBodyContains($email, '/reset-password');
        self::assertCount(1, self::getContainer()->get(UserRepository::class)->findAll());
        self::assertSame($passwordHash, $this->user('alice@example.com')->getPassword(), 'Its password stays.');
    }

    public function testTheTermsAndAPasswordOfTwelveCharacters(): void
    {
        $crawler = $this->client->request('GET', '/register');
        self::assertSame('I accept the terms of sale.', $crawler->filter('label[for="registration_acceptsTerms"]')->text());
        self::assertSame('/terms', $crawler->filter('label[for="registration_acceptsTerms"] a')->attr('href'));

        $this->client->submit($crawler->selectButton('Join the waiting list')->form(), [
            'registration[email]' => 'alice@example.com',
            'registration[displayName]' => 'Alice',
            'registration[plainPassword]' => 'too short',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'Accept the terms of sale to register.');
        self::assertSelectorTextContains('main form', 'This value is too short. It should have 12 characters or more.');
        self::assertEmailCount(0);
        self::assertSame([], self::getContainer()->get(UserRepository::class)->findAll());
    }

    public function testAnExpiredLinkLeadsToAPasswordReset(): void
    {
        self::mockTime('2026-11-22 10:00');
        $this->register('alice@example.com');
        $link = self::linkOf(self::lastEmail());

        self::mockTime('2026-11-24 10:01');
        $this->client->request('GET', $link);

        self::assertResponseRedirects('/reset-password', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'This link has expired. Choose a new password: its link verifies your email too.');
        self::assertFalse($this->user('alice@example.com')->isEmailVerified());
    }

    public function testAForgedLinkIsNotFound(): void
    {
        $this->register('alice@example.com');
        $link = self::linkOf(self::lastEmail());
        $id = $this->user('alice@example.com')->getId();

        $this->client->request('GET', str_replace('id=' . $id, 'id=' . ($id + 1), $link));

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->user('alice@example.com')->isEmailVerified());
    }

    public function testInFrenchTheEmailIsInFrenchToo(): void
    {
        $this->client->getCookieJar()->set(new Cookie(UserPreferredLocale::COOKIE, 'fr'));

        $crawler = $this->client->request('GET', '/register');
        self::assertSelectorTextSame('h1', 'Rejoindre Hop Garden');
        $this->client->submit($crawler->selectButton('M’inscrire sur la liste d’attente')->form(), [
            'registration[email]' => 'alice@example.com',
            'registration[displayName]' => 'Alice',
            'registration[plainPassword]' => self::PASSWORD,
            'registration[acceptsTerms]' => true,
        ]);

        self::assertEmailSubjectContains(self::lastEmail(), 'Vérifiez votre adresse pour Hop Garden');
        self::assertSame(SupportedLocale::French, $this->user('alice@example.com')->getPreferredLocale());
    }

    private function register(string $email, string $displayName = 'Alice'): void
    {
        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->selectButton('Join the waiting list')->form(), [
            'registration[email]' => $email,
            'registration[displayName]' => $displayName,
            'registration[plainPassword]' => self::PASSWORD,
            'registration[acceptsTerms]' => true,
        ]);
    }

    private function user(string $email): User
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(UserRepository::class)->findOneByEmail($email) ?? self::fail('No account for ' . $email . '.');
    }
}
