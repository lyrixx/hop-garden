<?php

namespace App\Tests\Functional\Account;

use App\Factory\UserFactory;
use App\Tests\Utils\Security\LogInTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Private by default: only the pages of the login are public, and the MCP server until OAuth.
 */
final class AccessTest extends WebTestCase
{
    use LogInTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[DataProvider('provideTheInternalPagesSendAVisitorToTheLoginCases')]
    public function testTheInternalPagesSendAVisitorToTheLogin(string $method, string $url): void
    {
        $this->client->request($method, $url);

        self::assertResponseRedirects('/login');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideTheInternalPagesSendAVisitorToTheLoginCases(): iterable
    {
        yield 'the chat' => ['GET', '/'];
        yield 'a message' => ['POST', '/chat'];
        yield 'the garden' => ['GET', '/garden'];
        yield 'the species' => ['GET', '/plants'];
        yield 'the spaces' => ['GET', '/spaces'];
        yield 'the notifications' => ['GET', '/notifications'];
        yield 'the account' => ['GET', '/account'];
        yield 'a live component' => ['POST', '/_components/SpeciesPicker'];
        yield 'the back-office' => ['GET', '/admin'];
    }

    public function testAVisitorFindsTheInternalPageAfterTheLogin(): void
    {
        $member = UserFactory::new()->approved()->create();
        $this->client->request('GET', '/spaces');
        self::assertResponseRedirects('/login');

        $this->forgetTheFailedLogins();
        $this->logInWithTheForm($this->client, $member->getEmail());

        self::assertResponseRedirects('/spaces', 302, 'Where it was going.');
    }

    #[TestWith(['/login'])]
    #[TestWith(['/register'])]
    #[TestWith(['/register/check-email'])]
    #[TestWith(['/register/waiting-list'])]
    #[TestWith(['/reset-password'])]
    #[TestWith(['/reset-password/check-email'])]
    public function testThePagesOfTheLoginArePublic(string $url): void
    {
        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.nav a[href="/garden"]', 'Nor the tabs of the garden.');
        self::assertSelectorNotExists('#notifications-stream', 'Nor its notifications.');
        self::assertSame(['/login', '/register'], $crawler->filter('.nav-visitor a')->each(static fn ($link): ?string => $link->attr('href')));
    }

    public function testAMemberOnTheWaitingListGetsNothingEvenWithASession(): void
    {
        $this->logIn($this->client, UserFactory::new()->verified()->create());

        $this->client->request('GET', '/garden');

        self::assertResponseStatusCodeSame(403, 'No ROLE_USER before the approval.');
    }

    public function testAMemberSeesTheGardenAndTheirAccountButNotTheBackOffice(): void
    {
        $this->logIn($this->client, UserFactory::new()->approved()->create(['displayName' => 'Alice']));

        $crawler = $this->client->request('GET', '/garden');

        self::assertResponseIsSuccessful();
        self::assertSame('Alice', $crawler->filter('.account-menu a[href="/account"]')->text());
        self::assertSelectorNotExists('.account-menu a[href="/admin"]');

        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }
}
