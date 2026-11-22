<?php

namespace App\Tests\Functional\Account;

use App\Account\Entity\User;
use App\Account\PasswordResetRequestRepository;
use App\Account\UserRepository;
use App\Factory\UserFactory;
use App\Tests\Utils\Mailer\SentEmailsTrait;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ResetPasswordTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use LogInTrait;
    use SentEmailsTrait;

    private const string NEW_PASSWORD = 'my brand new password';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->forgetTheFailedLogins();
    }

    public function testALinkByEmailThenANewPassword(): void
    {
        UserFactory::new()->approved()->create(['email' => 'alice@example.com', 'displayName' => 'Alice']);

        $this->ask('Alice@Example.com');

        self::assertResponseRedirects('/reset-password/check-email', 303);
        $email = self::lastEmail();
        self::assertEmailAddressContains($email, 'to', 'alice@example.com');
        self::assertEmailSubjectContains($email, 'Your new Hop Garden password');
        self::assertEmailHtmlBodyContains($email, 'This link works for one hour, once.');
        $link = self::linkOf($email);
        self::assertStringContainsString('/reset-password/reset/', $link);

        $this->client->request('GET', $link);
        self::assertResponseRedirects('/reset-password/reset', 302, 'The token leaves the URL.');
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Save my password')->form(), ['new_password[plainPassword]' => self::NEW_PASSWORD]);

        self::assertResponseRedirects('/login', 303, 'No login: the account may still be waiting.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Your password is changed: log in with it.');
        self::assertSame(0, self::getContainer()->get(PasswordResetRequestRepository::class)->count([]), 'Used.');

        $this->logInWithTheForm($this->client, 'alice@example.com', UserFactory::PASSWORD);
        $this->client->followRedirect();
        self::assertSelectorTextSame('main .warning', 'Invalid credentials.', 'The old one is gone.');
        $this->logInWithTheForm($this->client, 'alice@example.com', self::NEW_PASSWORD);
        self::assertResponseRedirects('/assistant');
    }

    public function testAnUnknownAddressGetsTheSameAnswerAndNoEmail(): void
    {
        $this->ask('nobody@example.com');

        self::assertResponseRedirects('/reset-password/check-email', 303);
        self::assertEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'If an account has this address, we just sent it a link');
    }

    public function testALinkWorksOnceAndOnlyTheLastOne(): void
    {
        UserFactory::new()->approved()->create(['email' => 'alice@example.com']);
        $this->ask('alice@example.com');
        $first = self::linkOf(self::lastEmail());
        $this->ask('alice@example.com');
        $second = self::linkOf(self::lastEmail());

        $this->client->request('GET', $first);
        $this->client->followRedirect();
        self::assertResponseRedirects('/reset-password', 303, 'Replaced by the second one.');

        $this->resetWith($second);
        self::assertResponseRedirects('/login', 303);

        $this->client->request('GET', $second);
        $this->client->followRedirect();
        self::assertResponseRedirects('/reset-password', 303, 'Used.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'This link does not work, or has expired: ask for a new one.');
    }

    public function testALinkExpiresAfterAnHour(): void
    {
        self::mockTime('2026-11-22 10:00');
        UserFactory::new()->approved()->create(['email' => 'alice@example.com']);
        $this->ask('alice@example.com');
        $link = self::linkOf(self::lastEmail());

        self::mockTime('2026-11-22 11:00');
        $this->client->request('GET', $link);
        $this->client->followRedirect();

        self::assertResponseRedirects('/reset-password', 303);
        self::assertTrue(password_verify(UserFactory::PASSWORD, $this->user('alice@example.com')->getPassword()), 'Unchanged.');
    }

    public function testItVerifiesTheEmailOfAnAccountThatNeverDid(): void
    {
        UserFactory::new()->create(['email' => 'alice@example.com']);

        $this->ask('alice@example.com');
        $this->resetWith(self::linkOf(self::lastEmail()));

        self::assertTrue($this->user('alice@example.com')->isWaiting(), 'On the waiting list now.');
    }

    private function ask(string $email): void
    {
        $crawler = $this->client->request('GET', '/reset-password');
        $this->client->submit($crawler->selectButton('Send me a link')->form(), ['password_reset_request[email]' => $email]);
    }

    private function resetWith(string $link): void
    {
        $this->client->request('GET', $link);
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Save my password')->form(), ['new_password[plainPassword]' => self::NEW_PASSWORD]);
    }

    private function user(string $email): User
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(UserRepository::class)->findOneByEmail($email) ?? self::fail('No account for ' . $email . '.');
    }
}
