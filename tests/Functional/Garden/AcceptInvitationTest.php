<?php

namespace App\Tests\Functional\Garden;

use App\Account\Entity\User;
use App\Account\Model\AccessStatus;
use App\Account\UserRepository;
use App\Factory\InvitationFactory;
use App\Factory\UserFactory;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Invitation;
use App\Garden\GardenRepository;
use App\Garden\Model\InvitationStatus;
use App\Locale\UserPreferredLocale;
use App\Mercure\Topics;
use App\Reminder\NotificationRepository;
use App\Tests\Utils\Garden\TwoGardens;
use App\Tests\Utils\Mailer\SentEmailsTrait;
use App\Tests\Utils\Mercure\RecordingHub;
use App\Tests\Utils\Mercure\TurboStream;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The link of an invitation, public: whoever has it joins the garden, with their account or a new one. A new account
 * comes in without the waiting list, at once with the address invited, once verified with another one. Who invited is
 * told. A link that no longer works says why, and nothing of its garden.
 */
final class AcceptInvitationTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use LogInTrait;
    use SentEmailsTrait;

    private const string PASSWORD = 'a long enough password';
    private const string LINK = '/invitations/' . TwoGardens::INVITATION_TOKEN;

    private KernelBrowser $client;
    private TwoGardens $gardens;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::mockTime('2026-11-22 10:00');
        $this->gardens = TwoGardens::create(self::getContainer());
        RecordingHub::reset();
    }

    public function testANewAccountWithTheAddressInvitedComesInAtOnce(): void
    {
        $crawler = $this->client->request('GET', self::LINK);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Join Allotment of Alice and Bob');
        self::assertSelectorTextContains('.plate-head', 'Alice invites you to garden together');
        self::assertSelectorTextContains('.invitation-card', 'Allotment of Alice and Bob · Lyon, Auvergne-Rhône-Alpes, France');
        self::assertSelectorTextContains('.invitation-card', 'Invitation for erin@example.com');
        self::assertSelectorTextContains('.invitation-card', 'No waiting list with this invitation');

        $crawler = $this->client->click($crawler->selectLink('Create my account')->link());
        self::assertResponseIsSuccessful();
        self::assertSame('erin@example.com', $crawler->filter('input[name="registration[email]"]')->attr('value'), 'The address invited.');
        $this->register($crawler, 'Erin');

        self::assertResponseRedirects('/garden', 303);
        self::assertEmailCount(0, 'The invitation verified the address.');
        $erin = $this->user('erin@example.com');
        self::assertSame(AccessStatus::Approved, $erin->getAccessStatus(), 'No waiting list.');
        self::assertSame('2026-11-22 10:00', $erin->getEmailVerifiedAt()?->format('Y-m-d H:i'));
        self::assertSame(InvitationStatus::Accepted, $this->invitation()->statusAt(new \DateTimeImmutable('2026-11-22 10:00')));

        $this->client->followRedirect();
        self::assertResponseIsSuccessful('Logged in.');
        self::assertSelectorTextContains('.flash-success', 'Welcome to Allotment of Alice and Bob: you are one of its members now.');
        self::assertSelectorTextContains('main', 'Rose by the gate', 'Their garden is hers now, the current one.');
        self::assertSelectorTextContains('.account-menu', 'Erin');

        $this->assertAliceIsTold('Erin joined Allotment of Alice and Bob.');
    }

    public function testAnotherAddressIsVerifiedFirstWithoutTheWaitingList(): void
    {
        $crawler = $this->client->request('GET', self::LINK . '/register');

        $this->register($crawler, 'Frank', 'Frank@Example.com');

        self::assertResponseRedirects('/register/check-email', 303);
        self::assertEmailCount(1);
        $email = self::lastEmail();
        self::assertEmailAddressContains($email, 'to', 'frank@example.com');
        self::assertEmailHtmlBodyContains($email, 'Verify your email, then log in: your invitation lets you in, no waiting list.');
        $frank = $this->user('frank@example.com');
        self::assertSame(AccessStatus::Approved, $frank->getAccessStatus());
        self::assertFalse($frank->isEmailVerified());
        self::assertTrue($this->isMember('frank@example.com'), 'In the garden already.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Your account is ready, and a member of Allotment of Alice and Bob.');

        $this->logInWithTheForm($this->client, 'frank@example.com', self::PASSWORD);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main .warning', 'Verify your email first', 'Not before its address is verified.');

        $this->client->request('GET', self::textLinksOf($email)[0]);
        self::assertResponseRedirects('/login', 303, 'No waiting list: the login.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Your email is verified: log in.');
        $this->forgetTheFailedLogins();
        $this->logInWithTheForm($this->client, 'frank@example.com', self::PASSWORD);
        $this->client->request('GET', '/garden');
        self::assertSelectorTextContains('main', 'Rose by the gate');

        $this->assertAliceIsTold('Frank joined Allotment of Alice and Bob, with the invitation sent to erin@example.com.');
    }

    /**
     * Carol logs in from the link: back on it, she joins. The garden is her current one from then on.
     */
    public function testAMemberWithAnAccountLogsInThenJoins(): void
    {
        InvitationFactory::createOne(['garden' => $this->gardens->allotment, 'email' => $this->gardens->carol->getEmail(), 'token' => $token = str_repeat('c', 43), 'sentAt' => new \DateTimeImmutable('2026-11-21')]);
        $crawler = $this->client->request('GET', '/invitations/' . $token);

        $this->client->click($crawler->selectLink('I have an account: log in')->link());
        $this->logInWithTheForm($this->client, $this->gardens->carol->getEmail());

        self::assertResponseRedirects('/invitations/' . $token, 302, 'Back on the invitation.');
        $crawler = $this->client->followRedirect();
        self::assertSelectorNotExists('.invitation-card .warning', 'Her address.');
        $this->client->submit($crawler->selectButton('Join Allotment of Alice and Bob')->form());

        self::assertResponseRedirects('/garden', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Rose by the gate', 'The garden she joined, not her balcony.');
        self::assertTrue($this->isMember($this->gardens->carol->getEmail()));
        self::assertSame(2, \count(self::getContainer()->get(GardenRepository::class)->findOfMember($this->gardens->carol)), 'Her balcony stays hers.');
        $this->assertAliceIsTold('Carol joined Allotment of Alice and Bob.');

        // Twice: a member already
        $this->client->request('GET', '/invitations/' . $token);
        self::assertSelectorTextContains('main', 'You are a member of Allotment of Alice and Bob already.');
    }

    public function testLoggedInWithAnotherAccountTheyJoinWithItOrLogOut(): void
    {
        $this->logIn($this->client, $this->gardens->carol);

        $crawler = $this->client->request('GET', self::LINK);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.invitation-card .warning', \sprintf('This invitation was sent to erin@example.com, and you use the account %s. Join with this account, or log out to use another one.', $this->gardens->carol->getEmail()));
        $this->client->submit($crawler->selectButton('Use another account')->form());
        self::assertResponseRedirects(self::LINK, 302, 'Back on the invitation, as a visitor.');
        $this->client->followRedirect();
        self::assertSelectorExists('a[href="' . self::LINK . '/register"]');
        self::assertSelectorNotExists('.account-menu');

        $this->logIn($this->client, $this->gardens->carol);
        $crawler = $this->client->request('GET', self::LINK);
        $this->client->submit($crawler->selectButton('Join with this account')->form());
        self::assertResponseRedirects('/garden', 303);
        self::assertTrue($this->isMember($this->gardens->carol->getEmail()));
        $this->assertAliceIsTold('Carol joined Allotment of Alice and Bob, with the invitation sent to erin@example.com.');
    }

    public function testAMemberAlreadyIsShownTheGarden(): void
    {
        $this->logIn($this->client, $this->gardens->bob);

        $crawler = $this->client->request('GET', self::LINK);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Vous êtes déjà membre de Allotment of Alice and Bob.', 'In his language.');
        $this->client->submit($crawler->selectButton('Ouvrir Allotment of Alice and Bob')->form());
        self::assertResponseRedirects('/garden', 303);
        self::assertSame(InvitationStatus::Pending, $this->invitation()->statusAt(new \DateTimeImmutable('2026-11-22 10:00')), 'Still for Erin.');
    }

    /**
     * @param \Closure(self): void $makeItUnavailable
     */
    #[DataProvider('provideALinkThatNoLongerWorksSaysWhyAndNothingOfItsGardenCases')]
    public function testALinkThatNoLongerWorksSaysWhyAndNothingOfItsGarden(\Closure $makeItUnavailable, int $status, string $why): void
    {
        $makeItUnavailable($this);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        foreach (['', '/register'] as $page) {
            $this->client->request('GET', self::LINK . $page);

            self::assertResponseStatusCodeSame($status, $page);
            self::assertSelectorTextSame('h1', 'This invitation no longer works');
            self::assertSelectorTextContains('main', $why);
            self::assertSelectorTextContains('main', 'Ask the person who sent it to you for a new one.');
            foreach (['Allotment of Alice and Bob', 'Alice', 'erin@example.com', 'Lyon'] as $secret) {
                self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->getContent());
            }
        }

        $this->client->request('POST', self::LINK . '/register', ['registration' => ['email' => 'erin@example.com', 'displayName' => 'Erin', 'plainPassword' => self::PASSWORD, 'acceptsTerms' => '1']]);
        self::assertResponseStatusCodeSame($status);
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('erin@example.com'), 'No account.');
    }

    /**
     * @return iterable<string, array{\Closure(self): void, int, string}>
     */
    public static function provideALinkThatNoLongerWorksSaysWhyAndNothingOfItsGardenCases(): iterable
    {
        yield 'expired' => [static fn (self $test) => self::mockTime('2026-12-04 00:00'), 410, 'This invitation has expired.'];
        yield 'revoked' => [static fn (self $test) => $test->invitation()->revoke(new \DateTimeImmutable('2026-11-21')), 410, 'This invitation was withdrawn.'];
        yield 'accepted' => [static fn (self $test) => $test->invitation()->accept(UserFactory::new()->approved()->create(), new \DateTimeImmutable('2026-11-21')), 410, 'This invitation was used already: each one lets one person in.'];
        yield 'its garden deleted' => [static fn (self $test) => self::getContainer()->get(GardenRepository::class)->remove($test->garden()), 404, 'This invitation does not exist, or no longer does: check the link. Its garden may have been deleted.'];
    }

    public function testAnUnknownLinkIsNotFoundInFrenchToo(): void
    {
        $this->client->getCookieJar()->set(new Cookie(UserPreferredLocale::COOKIE, 'fr'));

        $this->client->request('GET', '/invitations/' . str_repeat('x', 43));

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextSame('h1', 'Cette invitation ne marche plus');
        self::assertSelectorTextContains('main', 'Cette invitation n’existe pas, ou plus');
        $this->client->request('GET', '/invitations/too-short');
        self::assertResponseStatusCodeSame(404);
    }

    public function testInFrenchTheInvitationIsInFrench(): void
    {
        $this->client->getCookieJar()->set(new Cookie(UserPreferredLocale::COOKIE, 'fr'));

        $crawler = $this->client->request('GET', self::LINK);

        self::assertSelectorTextSame('h1', 'Rejoindre Allotment of Alice and Bob');
        self::assertSelectorTextContains('.invitation-card', 'Pas de liste d’attente avec cette invitation');
        $crawler = $this->client->click($crawler->selectLink('Créer mon compte')->link());
        $this->client->submit($crawler->selectButton('Créer mon compte et rejoindre Allotment of Alice and Bob')->form(), [
            'registration[displayName]' => 'Erin',
            'registration[plainPassword]' => self::PASSWORD,
            'registration[acceptsTerms]' => true,
        ]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Bienvenue dans Allotment of Alice and Bob');
        self::assertSame('fr', $this->user('erin@example.com')->getPreferredLocale()?->value, 'The language chosen as a visitor.');
        $this->assertAliceIsTold('Erin joined Allotment of Alice and Bob.', 'In her language: English.');
    }

    /**
     * The same answer as the registration: the email tells its owner, and the invitation waits.
     */
    public function testAnAddressWithAnAccountIsNotRegisteredAgain(): void
    {
        $crawler = $this->client->request('GET', self::LINK . '/register');

        $this->register($crawler, 'Mallory', $this->gardens->carol->getEmail());

        self::assertResponseRedirects('/register/check-email', 303);
        self::assertEmailSubjectContains(self::lastEmail(), 'You have a Hop Garden account already');
        self::assertSame(InvitationStatus::Pending, $this->invitation()->statusAt(new \DateTimeImmutable('2026-11-22 10:00')));
        self::assertFalse($this->isMember($this->gardens->carol->getEmail()));
    }

    /**
     * Their login is refused until the administrator decides: the invitation waits, they join once approved.
     */
    public function testAnAccountOnTheWaitingListWaitsForTheAdministrator(): void
    {
        UserFactory::new()->verified()->create(['email' => 'erin@example.com']);
        $this->client->request('GET', self::LINK);

        $this->logInWithTheForm($this->client, 'erin@example.com');

        $this->client->followRedirect();
        self::assertSelectorTextContains('main .warning', 'Your account is on the waiting list');
        self::assertSame(InvitationStatus::Pending, $this->invitation()->statusAt(new \DateTimeImmutable('2026-11-22 10:00')));
    }

    /**
     * Two people with the link at once: the first one joins, the other one is told.
     */
    public function testItLetsOnePersonIn(): void
    {
        $this->logIn($this->client, $this->gardens->carol);
        $form = $this->client->request('GET', self::LINK)->selectButton('Join with this account')->form();
        $this->invitation()->accept(UserFactory::new()->approved()->create(['displayName' => 'Dan']), new \DateTimeImmutable('2026-11-22 09:59'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->submit($form);

        self::assertResponseRedirects(self::LINK, 303);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('main', 'This invitation was used already');
        self::assertFalse($this->isMember($this->gardens->carol->getEmail()));
        self::assertSame([], self::newMembers(self::getContainer()->get(NotificationRepository::class)->findLatest($this->gardens->alice, $this->gardens->allotment)), 'Dan came in by the factory: nobody told.');
    }

    private function register(Crawler $crawler, string $name, ?string $email = null): void
    {
        $this->client->submit($crawler->filter('main form')->form(), [
            ...(null !== $email ? ['registration[email]' => $email] : []),
            'registration[displayName]' => $name,
            'registration[plainPassword]' => self::PASSWORD,
            'registration[acceptsTerms]' => true,
        ]);
    }

    /**
     * Their notification, and its toast if a page of theirs is open.
     */
    private function assertAliceIsTold(string $message, string $why = ''): void
    {
        $alice = $this->user($this->gardens->alice->getEmail());
        $allotment = $this->garden();
        self::assertSame([$message], self::newMembers(self::getContainer()->get(NotificationRepository::class)->findLatest($alice, $allotment)), $why);

        $streams = TurboStream::parseAll(implode('', RecordingHub::published(self::getContainer()->get(Topics::class)->notifications($alice, $allotment))));
        self::assertSame($message, $streams[0]->content->filter('.toast-member p')->text(), 'Its toast.');
        self::assertSame('/gardens/' . $allotment->getId() . '/members', $streams[0]->content->filter('.toast-member a')->attr('href'));
        self::assertSame([], $this->bobsNewMembers(), 'Bob invited nobody.');

        $this->client->getCookieJar()->clear();
        $this->logIn($this->client, $alice);
        $crawler = $this->client->request('GET', '/notifications');
        self::assertSame($message, $crawler->filter('.notification-member p')->first()->text());
        $crawler = $this->client->request('GET', '/gardens/' . $allotment->getId() . '/members');
        self::assertCount(3, $crawler->filter('.member'), 'A new member.');
    }

    /**
     * @return list<string>
     */
    private function bobsNewMembers(): array
    {
        return self::newMembers(self::getContainer()->get(NotificationRepository::class)->findLatest($this->gardens->bob, $this->gardens->allotment));
    }

    /**
     * @param list<\App\Reminder\Entity\Notification> $notifications
     *
     * @return list<string>
     */
    private static function newMembers(array $notifications): array
    {
        return array_values(array_map(static fn ($notification): string => $notification->getMessage(), array_filter($notifications, static fn ($notification): bool => null !== $notification->getInvitation())));
    }

    private function isMember(string $email): bool
    {
        return \in_array($email, array_map(static fn (User $member): string => $member->getEmail(), $this->garden()->getMembers()), true);
    }

    private function invitation(): Invitation
    {
        return self::getContainer()->get(EntityManagerInterface::class)->find(Invitation::class, $this->gardens->invitation->getId()) ?? self::fail('The invitation is gone.');
    }

    private function garden(): Garden
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(EntityManagerInterface::class)->find(Garden::class, $this->gardens->allotment->getId()) ?? self::fail('The garden is gone.');
    }

    private function user(string $email): User
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(UserRepository::class)->findOneByEmail($email) ?? self::fail('No account for ' . $email . '.');
    }
}
