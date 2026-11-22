<?php

namespace App\Tests\Functional\Account;

use App\Account\Entity\User;
use App\Factory\UserFactory;
use App\Tests\Utils\Security\LogInTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

final class LoginTest extends WebTestCase
{
    use LogInTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->forgetTheFailedLogins();
    }

    public function testLogInThenLogOut(): void
    {
        $this->joinTheGardenOfTheTest(UserFactory::new()->approved()->create(['email' => 'alice@example.com', 'displayName' => 'Alice']));

        $this->logInWithTheForm($this->client, 'Alice@Example.com');

        self::assertResponseRedirects('/assistant', 302, 'The chat, whatever the case of the email.');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextSame('h1', 'Your gardening assistant');
        self::assertSame('Alice', $crawler->filter('.account-menu a[href="/account"]')->text());

        $this->client->submit($crawler->filter('.account-menu form')->form());

        self::assertResponseRedirects('/');
        $this->client->request('GET', '/garden');
        self::assertResponseRedirects('/login', 302, 'Logged out.');
    }

    public function testAWrongPasswordAndAnUnknownEmailSayTheSame(): void
    {
        UserFactory::new()->approved()->create(['email' => 'alice@example.com']);

        $this->logInWithTheForm($this->client, 'alice@example.com', 'not her password');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextSame('main .warning', 'Invalid credentials.');
        self::assertSame('alice@example.com', $crawler->filter('input[name="_username"]')->attr('value'), 'Typed again for them.');

        $this->logInWithTheForm($this->client, 'nobody@example.com', 'a password');
        $this->client->followRedirect();
        self::assertSelectorTextSame('main .warning', 'Invalid credentials.');
    }

    /**
     * @param \Closure(): PersistentObjectFactory<User> $account
     */
    #[DataProvider('provideAnAccountNotApprovedIsToldWhyAfterItsPasswordCases')]
    public function testAnAccountNotApprovedIsToldWhyAfterItsPassword(\Closure $account, string $message): void
    {
        $account()->create(['email' => 'alice@example.com']);

        $this->logInWithTheForm($this->client, 'alice@example.com', 'not her password');
        $this->client->followRedirect();
        self::assertSelectorTextSame('main .warning', 'Invalid credentials.', 'Nothing about the account without its password.');

        $this->logInWithTheForm($this->client, 'alice@example.com');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextSame('main .warning', $message);
        $this->client->request('GET', '/garden');
        self::assertResponseRedirects('/login', 302, 'Not logged in.');
    }

    /**
     * @return iterable<string, array{\Closure(): PersistentObjectFactory<User>, string}>
     */
    public static function provideAnAccountNotApprovedIsToldWhyAfterItsPasswordCases(): iterable
    {
        yield 'its email not verified' => [static fn () => UserFactory::new(), 'Verify your email first, with the link we sent you. Lost it? Choose a new password: its link verifies your email too.'];
        yield 'on the waiting list' => [static fn () => UserFactory::new()->verified(), 'Your account is on the waiting list: we write to you as soon as it is approved.'];
        yield 'refused' => [static fn () => UserFactory::new()->refused(), 'Your request to join Hop Garden was declined.'];
    }

    public function testFiveFailedAttemptsCloseTheDoorForAWhile(): void
    {
        UserFactory::new()->approved()->create(['email' => 'alice@example.com']);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->logInWithTheForm($this->client, 'alice@example.com', 'guess ' . $attempt);
        }
        $this->logInWithTheForm($this->client, 'alice@example.com');

        $this->client->followRedirect();
        self::assertSelectorTextContains('main .warning', 'Too many failed login attempts, please try again in');
        $this->client->request('GET', '/garden');
        self::assertResponseRedirects('/login', 302, 'Even the right password waits.');
    }

    public function testRememberMeOutlivesTheSession(): void
    {
        $this->joinTheGardenOfTheTest(UserFactory::new()->approved()->create(['email' => 'alice@example.com']));

        $this->logInWithTheForm($this->client, 'alice@example.com', rememberMe: true);

        $cookieJar = $this->client->getCookieJar();
        self::assertNotNull($cookieJar->get('REMEMBERME'));
        $cookieJar->expire('MOCKSESSID');
        $this->client->request('GET', '/garden');
        self::assertResponseIsSuccessful('Logged in again by the cookie.');
    }

    public function testAMemberWithoutGardenMakesTheirFirstOneAfterTheLogin(): void
    {
        UserFactory::new()->approved()->create(['email' => 'alice@example.com']);

        $this->logInWithTheForm($this->client, 'alice@example.com');
        self::assertResponseRedirects('/assistant');
        $this->client->followRedirect();

        self::assertResponseRedirects('/gardens/new', 303, 'The pages of a garden need one.');
        $this->client->followRedirect();
        self::assertSelectorTextSame('h1', 'Your first garden');
    }

    public function testAMemberLoggedInGoesStraightToTheChat(): void
    {
        $this->logIn($this->client);

        $this->client->request('GET', '/login');
        self::assertResponseRedirects('/assistant');

        $this->client->request('GET', '/register');
        self::assertResponseRedirects('/assistant');
    }
}
