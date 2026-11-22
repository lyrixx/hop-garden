<?php

namespace App\Tests\Functional\Account;

use App\Account\Entity\User;
use App\Account\UserRepository;
use App\Factory\UserFactory;
use App\Locale\Model\SupportedLocale;
use App\Locale\UserPreferredLocale;
use App\Tests\Utils\Mailer\SentEmailsTrait;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

final class AccountTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use LogInTrait;
    use SentEmailsTrait;

    private const string NEW_PASSWORD = 'my brand new password';

    private KernelBrowser $client;

    private User $member;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->forgetTheFailedLogins();
        $this->member = $this->logIn($this->client, UserFactory::new()->approved()->create(['email' => 'alice@example.com', 'displayName' => 'Alice']));
    }

    public function testTheNameAndTheLanguage(): void
    {
        $crawler = $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        self::assertSame('Alice', $crawler->filter('#profile_displayName')->attr('value'));
        self::assertSame('en', $crawler->filter('[name="profile[preferredLocale]"]:checked')->attr('value'), 'The language of the interface, until chosen.');

        $this->client->submit($crawler->filter('form#profile')->form(), ['profile[displayName]' => ' Alice Smith ', 'profile[preferredLocale]' => 'fr']);

        self::assertResponseRedirects('/account', 303);
        $crawler = $this->client->followRedirect();
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextContains('.flash-success', 'Votre compte est enregistré.');
        self::assertSame('Alice Smith', $crawler->filter('.account-menu a[href="/account"]')->text());
        self::assertSame('Alice Smith', $this->member()->getDisplayName());
        self::assertSame(SupportedLocale::French, $this->member()->getPreferredLocale());
        self::assertSame('fr', $this->client->getCookieJar()->get(UserPreferredLocale::COOKIE)?->getValue());
    }

    public function testANewEmailOnceItsLinkIsOpened(): void
    {
        $this->changeEmailTo('Alice.Smith@Example.com');

        self::assertResponseRedirects('/account', 303);
        $email = self::lastEmail();
        self::assertEmailAddressContains($email, 'to', 'alice.smith@example.com');
        self::assertEmailSubjectContains($email, 'Confirm your new email');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'We sent a link to alice.smith@example.com: open it to confirm the change.');
        self::assertSame('alice@example.com', $this->member()->getEmail(), 'Not before its link.');

        $link = self::linkOf($email);
        $this->client->request('GET', $link);

        self::assertResponseRedirects('/account', 303);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful('Still logged in, under the new email.');
        self::assertSelectorTextContains('.flash-success', 'Your email is now alice.smith@example.com.');
        self::assertSame('alice.smith@example.com', $this->member()->getEmail());

        $this->client->request('GET', $link);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'This link no longer works for this account.', 'Once.');

        $this->client->restart();
        $this->logInWithTheForm($this->client, 'alice.smith@example.com');
        self::assertResponseRedirects('/assistant');
    }

    public function testANewEmailNeedsTheCurrentPassword(): void
    {
        $this->changeEmailTo('alice.smith@example.com', 'not her password');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form#email', 'This is not your current password.');
        self::assertEmailCount(0);
    }

    public function testTheEmailOfAnotherAccountGetsTheSameAnswerAndItsOwnerAnEmail(): void
    {
        UserFactory::new()->approved()->create(['email' => 'bob@example.com', 'displayName' => 'Bob']);

        $this->changeEmailTo('bob@example.com');

        self::assertResponseRedirects('/account', 303);
        $email = self::lastEmail();
        self::assertEmailAddressContains($email, 'to', 'bob@example.com');
        self::assertEmailSubjectContains($email, 'You have a Hop Garden account already');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'We sent a link to bob@example.com');
        self::assertSame('alice@example.com', $this->member()->getEmail());
    }

    public function testTheLinkOfANewEmailExpiresAfterAnHour(): void
    {
        self::mockTime('2026-11-22 10:00');
        $this->changeEmailTo('alice.smith@example.com');
        $link = self::linkOf(self::lastEmail());

        self::mockTime('2026-11-22 11:01');
        $this->client->request('GET', $link);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-warning', 'This link has expired: change your email again.');
        self::assertSame('alice@example.com', $this->member()->getEmail());
    }

    public function testANewPasswordNeedsTheCurrentOne(): void
    {
        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->filter('form#password')->form(), ['password_change[currentPassword]' => 'not her password', 'password_change[newPassword]' => self::NEW_PASSWORD]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form#password', 'This is not your current password.');

        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->filter('form#password')->form(), ['password_change[currentPassword]' => UserFactory::PASSWORD, 'password_change[newPassword]' => self::NEW_PASSWORD]);

        self::assertResponseRedirects('/account', 303);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful('This session goes on.');
        self::assertSelectorTextContains('.flash-success', 'Your password is changed.');
        self::assertTrue(password_verify(self::NEW_PASSWORD, $this->member()->getPassword()));
    }

    public function testDeletingTheAccountNeedsThePassword(): void
    {
        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->filter('form#deletion')->form(), ['account_deletion[currentPassword]' => 'not her password']);
        self::assertResponseStatusCodeSame(422);
        $id = $this->member()->getId();

        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->filter('form#deletion')->form(), ['account_deletion[currentPassword]' => UserFactory::PASSWORD]);

        self::assertResponseRedirects('/');
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull(self::getContainer()->get(UserRepository::class)->find($id));
        $this->client->request('GET', '/garden');
        self::assertResponseRedirects('/login', 302, 'Logged out.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Your account is deleted.');
    }

    private function changeEmailTo(string $email, string $password = UserFactory::PASSWORD): Crawler
    {
        $crawler = $this->client->request('GET', '/account');

        return $this->client->submit($crawler->filter('form#email')->form(), ['email_change[newEmail]' => $email, 'email_change[currentPassword]' => $password]);
    }

    private function member(): User
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(UserRepository::class)->find($this->member->getId()) ?? self::fail('The member is gone.');
    }
}
