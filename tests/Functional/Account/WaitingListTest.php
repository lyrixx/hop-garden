<?php

namespace App\Tests\Functional\Account;

use App\Account\Entity\User;
use App\Account\Model\AccessStatus;
use App\Account\UserRepository;
use App\Factory\UserFactory;
use App\Locale\Model\SupportedLocale;
use App\Tests\Utils\Mailer\SentEmailsTrait;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The back-office of EasyAdmin, for the administrator: the waiting list first, then every account.
 */
final class WaitingListTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use LogInTrait;
    use SentEmailsTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->forgetTheFailedLogins();
    }

    public function testOnlyTheAdministratorEntersTheBackOffice(): void
    {
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/login');

        $this->logIn($this->client);
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403, 'A member is no administrator.');

        $this->logIn($this->client, UserFactory::new()->admin()->create());
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/waiting-list', 302, 'The waiting list first.');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextSame('h1', 'Waiting list');
        self::assertSame(['Waiting list', 'Accounts', 'Back to Hop Garden'], $crawler->filter('.ea-sidebar-item-label')->each(static fn (Crawler $label): string => $label->text()));
    }

    public function testTheWaitingListShowsTheVerifiedAccountsWithoutDecision(): void
    {
        UserFactory::new()->verified()->create(['displayName' => 'Carol', 'registeredAt' => new \DateTimeImmutable('2026-11-03')]);
        UserFactory::new()->verified()->create(['displayName' => 'Bob', 'registeredAt' => new \DateTimeImmutable('2026-11-02')]);
        UserFactory::new()->create(['displayName' => 'Unverified']);
        UserFactory::new()->approved()->create(['displayName' => 'Approved']);
        UserFactory::new()->refused()->create(['displayName' => 'Refused']);
        $this->logIn($this->client, UserFactory::new()->admin()->create(['displayName' => 'Admin']));

        $crawler = $this->client->request('GET', '/admin/waiting-list');

        self::assertResponseIsSuccessful();
        self::assertSame(['Bob', 'Carol'], $this->names($crawler), 'The oldest first.');
        self::assertCount(2, $crawler->filter('form[action*="/approve"]'));
        self::assertCount(2, $crawler->filter('form[action*="/refuse"]'));
    }

    public function testApprovedTheMemberGetsAnEmailInTheirLanguageAndComesIn(): void
    {
        self::mockTime('2026-11-22 10:00');
        $dave = UserFactory::new()->verified()->create(['email' => 'dave@example.com', 'displayName' => 'Dave', 'preferredLocale' => SupportedLocale::French]);
        $this->logIn($this->client, UserFactory::new()->admin()->create(['preferredLocale' => SupportedLocale::English]));

        $crawler = $this->client->request('GET', '/admin/waiting-list');
        $this->client->submit($crawler->filter('form[action*="/approve"]')->form());

        self::assertResponseRedirects('/admin/waiting-list');
        $email = self::lastEmail();
        self::assertEmailAddressContains($email, 'to', 'dave@example.com');
        self::assertEmailSubjectContains($email, 'Vous pouvez entrer', 'In the language of Dave, not of the administrator.');
        self::assertEmailHtmlBodyContains($email, 'Bonjour Dave,');
        self::assertStringEndsWith('/login', self::linkOf($email));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#flash-messages', 'Dave is approved, and gets the email.');
        self::assertSame([], $this->names($crawler));
        self::assertSame(AccessStatus::Approved, $this->reload($dave)->getAccessStatus());
        self::assertSame('2026-11-22 10:00', $this->reload($dave)->getApprovedAt()?->format('Y-m-d H:i'));

        $this->client->restart();
        $this->logInWithTheForm($this->client, 'dave@example.com');
        self::assertResponseRedirects('/', 302, 'In.');
    }

    public function testRefusedTheMemberGetsAnEmailAndStaysOut(): void
    {
        $dave = UserFactory::new()->verified()->create(['email' => 'dave@example.com', 'displayName' => 'Dave']);
        $this->logIn($this->client, UserFactory::new()->admin()->create());

        $crawler = $this->client->request('GET', '/admin/waiting-list');
        $this->client->submit($crawler->filter('form[action*="/refuse"]')->form());

        self::assertResponseRedirects('/admin/waiting-list');
        self::assertEmailSubjectContains(self::lastEmail(), 'Your request to join Hop Garden');
        self::assertSame(AccessStatus::Refused, $this->reload($dave)->getAccessStatus());

        $this->client->restart();
        $this->logInWithTheForm($this->client, 'dave@example.com');
        $this->client->followRedirect();
        self::assertSelectorTextSame('main .warning', 'Your request to join Hop Garden was declined.');
    }

    public function testASecondClickDecidesNothingMore(): void
    {
        $dave = UserFactory::new()->verified()->create(['displayName' => 'Dave']);
        $this->logIn($this->client, UserFactory::new()->admin()->create());
        $approve = $this->client->request('GET', '/admin/waiting-list')->filter('form[action*="/approve"]')->form();

        $this->client->submit($approve);
        self::assertEmailCount(1);
        $this->client->submit($approve);

        self::assertResponseRedirects('/admin/waiting-list');
        self::assertEmailCount(0, null, 'One decision, one email.');
        $this->client->followRedirect();
        self::assertAnySelectorTextContains('#flash-messages *', 'Dave has a decision already.');
        self::assertSame(AccessStatus::Approved, $this->reload($dave)->getAccessStatus());
    }

    public function testADecisionNeedsItsToken(): void
    {
        $dave = UserFactory::new()->verified()->create();
        $this->logIn($this->client, UserFactory::new()->admin()->create());

        $this->client->request('POST', '/admin/waiting-list/' . $dave->getId() . '/approve?token=forged');

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reload($dave)->isWaiting());
        self::assertEmailCount(0);
    }

    public function testTheAccountsWithTheirAccess(): void
    {
        UserFactory::new()->verified()->create(['displayName' => 'Dave', 'registeredAt' => new \DateTimeImmutable('2026-11-03')]);
        UserFactory::new()->approved()->create(['displayName' => 'Alice', 'registeredAt' => new \DateTimeImmutable('2026-11-02')]);
        $this->logIn($this->client, UserFactory::new()->admin()->create(['displayName' => 'Admin', 'registeredAt' => new \DateTimeImmutable('2026-11-01')]));

        $crawler = $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertSame(['Dave', 'Alice', 'Admin'], $this->names($crawler), 'The newest first.');
        self::assertSame(['Waiting', 'Approved', 'Approved'], $crawler->filter('td[data-column="accessStatus"]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertCount(0, $crawler->filter('form[action*="/approve"]'), 'Decided on the waiting list only.');
    }

    public function testTheAccountsByAccess(): void
    {
        UserFactory::new()->verified()->create(['displayName' => 'Dave']);
        $carol = UserFactory::new()->refused()->create(['displayName' => 'Carol']);
        $this->logIn($this->client, UserFactory::new()->admin()->create(['displayName' => 'Admin']));

        $crawler = $this->client->request('GET', '/admin/users', ['filters' => ['accessStatus' => ['comparison' => '=', 'value' => 'refused']]]);

        self::assertResponseIsSuccessful();
        self::assertSame(['Carol'], $this->names($crawler));

        $this->client->request('GET', '/admin/users/' . $carol->getId());
        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextSame('.badge, [class*="badge"]', 'Refused');
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('td[data-column="displayName"]')->each(static fn (Crawler $cell): string => trim($cell->text()));
    }

    private function reload(User $user): User
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(UserRepository::class)->find($user->getId()) ?? self::fail('The account is gone.');
    }
}
