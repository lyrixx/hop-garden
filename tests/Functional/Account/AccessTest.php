<?php

namespace App\Tests\Functional\Account;

use App\Factory\UserFactory;
use App\Tests\Utils\Garden\GardenPathsTrait;
use App\Tests\Utils\Security\LogInTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Private by default: only the public pages, the pages of the login and the choice of a language are public, and the
 * MCP server until OAuth.
 */
final class AccessTest extends WebTestCase
{
    use GardenPathsTrait;
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
        // Whatever the garden, before it is looked for: a visitor learns nothing of the gardens there are
        yield 'the chat' => ['GET', '/g/a-garden/assistant'];
        yield 'a message' => ['POST', '/g/a-garden/chat'];
        yield 'a new conversation' => ['POST', '/g/a-garden/chat/new'];
        yield 'the garden' => ['GET', '/g/a-garden/garden'];
        yield 'the species' => ['GET', '/g/a-garden/species'];
        yield 'the spaces' => ['GET', '/g/a-garden/spaces'];
        yield 'the notifications' => ['GET', '/g/a-garden/notifications'];
        yield 'a live component' => ['POST', '/g/a-garden/_components/SpeciesPicker'];
        yield 'the assistant of the last garden' => ['GET', '/assistant'];
        yield 'a path of before the gardens' => ['GET', '/plants'];
        yield 'the account' => ['GET', '/account'];
        yield 'the back-office' => ['GET', '/admin'];
    }

    public function testAVisitorFindsTheInternalPageAfterTheLogin(): void
    {
        $member = $this->gardenOfTheTest()->getOwner();
        $this->client->request('GET', self::inGarden('/spaces'));
        self::assertResponseRedirects('/login');

        $this->forgetTheFailedLogins();
        $this->logInWithTheForm($this->client, $member->getEmail());

        self::assertResponseRedirects(self::inGarden('/spaces'), 302, 'Where it was going.');
    }

    /**
     * By the name of their routes (home, legal_*). The choice of a language: HomeControllerTest, LanguageOfAVisitorTest.
     */
    #[TestWith(['/'])]
    #[TestWith(['/legal-notice'])]
    #[TestWith(['/terms'])]
    #[TestWith(['/privacy'])]
    public function testThePublicPagesArePublic(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
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
        self::assertSelectorNotExists('.nav a[href="' . self::inGarden('/garden') . '"]', 'Nor the tabs of the garden.');
        self::assertSelectorNotExists('#notifications-stream', 'Nor its notifications.');
        self::assertSame(['/login', '/register'], $crawler->filter('.nav-visitor a')->each(static fn ($link): ?string => $link->attr('href')));
        self::assertSame('/', $crawler->filter('.topbar .brand')->attr('href'), 'The public page: the chat would send back here.');
    }

    public function testAMemberOnTheWaitingListGetsNothingEvenWithASession(): void
    {
        $this->logIn($this->client, UserFactory::new()->verified()->create());

        $this->client->request('GET', self::inGarden('/garden'));

        self::assertResponseStatusCodeSame(403, 'No ROLE_USER before the approval.');
    }

    public function testAMemberSeesTheGardenAndTheirAccountButNotTheBackOffice(): void
    {
        $this->logIn($this->client, UserFactory::new()->approved()->create(['displayName' => 'Alice']));

        $crawler = $this->client->request('GET', self::inGarden('/garden'));

        self::assertResponseIsSuccessful();
        self::assertSame('/assistant', $crawler->filter('.topbar .brand')->attr('href'));
        self::assertSame('Alice', $crawler->filter('.account-menu a[href="/account"]')->text());
        self::assertSelectorNotExists('.account-menu a[href="/admin"]');

        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }
}
