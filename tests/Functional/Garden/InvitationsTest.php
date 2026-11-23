<?php

namespace App\Tests\Functional\Garden;

use App\Factory\GardenFactory;
use App\Factory\InvitationFactory;
use App\Factory\UserFactory;
use App\Garden\Entity\Invitation;
use App\Garden\InvitationRepository;
use App\Locale\Model\SupportedLocale;
use App\Tests\Utils\Garden\TwoGardens;
use App\Tests\Utils\Mailer\SentEmailsTrait;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Its owner invites into the garden by email, from the page of its members: the email carries the link, the page shows
 * it too, once, to send another way. An invitation is sent again, or shown again, with a new link only: the database
 * keeps the hash of its token. Accepting it: AcceptInvitationTest.
 */
final class InvitationsTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use LogInTrait;
    use SentEmailsTrait;

    private KernelBrowser $client;
    private TwoGardens $gardens;
    private string $members;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The quotas outlive the kernel of a request, as in production
        self::getContainer()->get('garden_quota.cache')->clear();
        self::mockTime('2026-11-22 10:00');
        $this->gardens = TwoGardens::create(self::getContainer());
        $this->members = '/gardens/' . $this->gardens->allotment->getId() . '/members';
        $this->logIn($this->client, $this->gardens->alice);
    }

    public function testTheOwnerInvitesByEmailAndSeesTheLinkAtOnce(): void
    {
        $crawler = $this->client->request('GET', $this->members);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Allotment of Alice and Bob');

        $this->client->submit($crawler->selectButton('Invite')->form(), ['invitation[email]' => ' Frank@Example.com ']);

        self::assertResponseRedirects($this->members, 303);
        self::assertEmailCount(1);
        $email = self::lastEmail();
        self::assertEmailAddressContains($email, 'to', 'frank@example.com');
        self::assertEmailSubjectContains($email, 'Alice invites you to Allotment of Alice and Bob on Hop Garden');
        self::assertEmailHtmlBodyContains($email, 'Alice invites you to garden together in Allotment of Alice and Bob');
        self::assertEmailHtmlBodyContains($email, 'No waiting list with this invitation');
        self::assertEmailHtmlBodyContains($email, 'This link works until 6 December 2026, once.');
        [$link] = self::textLinksOf($email);
        self::assertMatchesRegularExpression('{^http://localhost/invitations/[A-Za-z0-9_-]{43}$}', $link);

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Invitation sent to frank@example.com.');
        $shown = $crawler->filter('.invitation.is-shown');
        self::assertCount(1, $shown);
        self::assertSame('frank@example.com', $shown->filter('strong')->text());
        self::assertSame($link, $shown->filter('input')->attr('value'), 'The link of the email, to copy.');
        self::assertSame('clipboard', $shown->filter('.invitation-link')->attr('data-controller'));
        self::assertSame('false', $shown->filter('.invitation-link')->attr('data-clipboard-auto-value'), 'Copied when asked only.');
        self::assertSame(['frank@example.com', 'erin@example.com'], $crawler->filter('.invitation strong')->each(static fn (Crawler $email): string => $email->text()), 'The newest first.');
        self::assertStringContainsString('Invited on 22 November 2026 by Alice · expires on 6 December 2026', $shown->filter('.invitation-head .meta')->text());

        $invitation = $this->invitation($link);
        self::assertSame('frank@example.com', $invitation->getEmail());
        self::assertSame($this->gardens->allotment->getId(), $invitation->getGarden()->getId());

        $crawler = $this->client->request('GET', $this->members);
        self::assertCount(0, $crawler->filter('.invitation.is-shown, .invitation-link'), 'Shown once: its hash only is kept.');
    }

    /**
     * In the language of the account of the address, else in the one of the owner.
     */
    public function testTheEmailSpeaksTheLanguageOfTheInviteeIfItHasAnAccountElseTheOneOfTheOwner(): void
    {
        UserFactory::new()->approved()->create(['email' => 'zoe@example.com', 'displayName' => 'Zoé', 'preferredLocale' => SupportedLocale::French]);
        $crawler = $this->client->request('GET', $this->members);

        $this->client->submit($crawler->selectButton('Invite')->form(), ['invitation[email]' => 'zoe@example.com']);

        self::assertEmailSubjectContains(self::lastEmail(), 'Alice vous invite dans Allotment of Alice and Bob sur Hop Garden');
        self::assertEmailHtmlBodyContains(self::lastEmail(), 'Ce lien marche jusqu’au 6 décembre 2026, une fois.');

        $owner = UserFactory::new()->approved()->create(['displayName' => 'Jules', 'preferredLocale' => SupportedLocale::French]);
        $orchard = GardenFactory::new()->ownedBy($owner)->create(['name' => 'Le verger']);
        $this->client->getCookieJar()->clear();
        $this->logIn($this->client, $owner);
        $crawler = $this->client->request('GET', '/gardens/' . $orchard->getId() . '/members');
        $this->client->submit($crawler->selectButton('Inviter')->form(), ['invitation[email]' => 'nobody@example.com']);
        self::assertEmailSubjectContains(self::lastEmail(), 'Jules vous invite dans Le verger sur Hop Garden', 'No account: the language of the owner.');

        UserFactory::new()->approved()->create(['email' => 'ann@example.com', 'preferredLocale' => SupportedLocale::English]);
        $this->client->submit($crawler->selectButton('Inviter')->form(), ['invitation[email]' => 'ann@example.com']);
        self::assertEmailSubjectContains(self::lastEmail(), 'Jules invites you to Le verger on Hop Garden', 'Hers, whatever the page.');
    }

    public function testAnAddressInvitedAgainKeepsItsInvitationWithANewLink(): void
    {
        $crawler = $this->client->request('GET', $this->members);

        $this->client->submit($crawler->selectButton('Invite')->form(), ['invitation[email]' => 'ERIN@example.com']);

        $invitation = $this->invitation(self::linkOf(self::lastEmail()));
        self::assertSame($this->gardens->invitation->getId()->toRfc4122(), $invitation->getId()->toRfc4122(), 'One per address in a garden.');
        self::assertSame('2026-12-06 10:00', $invitation->getExpiresAt()->format('Y-m-d H:i'), '14 days again.');
        self::assertNull(self::getContainer()->get(InvitationRepository::class)->findOneByToken(TwoGardens::INVITATION_TOKEN), 'The link before stops working.');
    }

    public function testSentAgainTheLinkBeforeStopsWorking(): void
    {
        $crawler = $this->client->request('GET', $this->members);

        $this->client->submit($this->rowOf($crawler, 'erin@example.com')->selectButton('Send again')->form());

        self::assertResponseRedirects($this->members, 303);
        self::assertEmailCount(1);
        $link = self::linkOf(self::lastEmail());
        self::assertEmailAddressContains(self::lastEmail(), 'to', 'erin@example.com');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Invitation sent again to erin@example.com, with a new link: the one before no longer works.');
        self::assertSame($link, $crawler->filter('.invitation.is-shown input')->attr('value'));
        self::assertSame($this->gardens->invitation->getId()->toRfc4122(), $this->invitation($link)->getId()->toRfc4122());

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/invitations/' . TwoGardens::INVITATION_TOKEN);
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('main', 'This invitation does not exist, or no longer does');
    }

    public function testANewLinkIsCopiedWithoutEmail(): void
    {
        $crawler = $this->client->request('GET', $this->members);

        $this->client->submit($this->rowOf($crawler, 'erin@example.com')->selectButton('Copy a new link')->form());

        self::assertResponseRedirects($this->members, 303);
        self::assertEmailCount(0);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'A new link for erin@example.com: the one before no longer works.');
        $shown = $crawler->filter('.invitation.is-shown .invitation-link');
        self::assertSame('true', $shown->attr('data-clipboard-auto-value'), 'Copied as soon as shown, where the browser lets it.');
        $this->invitation((string) $shown->filter('input')->attr('value'));
        self::assertNull(self::getContainer()->get(InvitationRepository::class)->findOneByToken(TwoGardens::INVITATION_TOKEN));
    }

    public function testRevokedItsLinkNoLongerWorks(): void
    {
        $crawler = $this->client->request('GET', $this->members);
        $row = $this->rowOf($crawler, 'erin@example.com');
        self::assertSame('Revoke the invitation of erin@example.com? Its link stops working.', $row->filter('form[action$="/revoke"]')->attr('data-turbo-confirm'));

        $this->client->submit($row->selectButton('Revoke')->form());

        self::assertResponseRedirects($this->members, 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'The invitation of erin@example.com is revoked: its link no longer works.');
        self::assertSelectorTextContains('.garden-invitations', 'No invitation waiting.');
        self::assertEmailCount(0);

        // Twice, from a page left open: nothing more
        $this->client->submit($row->selectButton('Revoke')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'The invitation of erin@example.com was accepted or revoked meanwhile.');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/invitations/' . TwoGardens::INVITATION_TOKEN);
        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('main', 'This invitation was withdrawn.');
    }

    public function testAMemberIsNoAddressToInvite(): void
    {
        $crawler = $this->client->request('GET', $this->members);

        $crawler = $this->client->submit($crawler->selectButton('Invite')->form(), ['invitation[email]' => ' ' . strtoupper($this->gardens->bob->getEmail())]);

        self::assertResponseStatusCodeSame(422, 'Shown again: Turbo Drive renders it in place.');
        self::assertSelectorTextContains('form.invitation-form', 'Bob is a member of the garden already.');
        $this->client->submit($crawler->selectButton('Invite')->form(), ['invitation[email]' => 'not an address']);
        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
    }

    public function testTheEmailsOfADayAreCountedPerGarden(): void
    {
        $limiter = self::getContainer()->get('limiter.garden_invitations');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);
        $limiter->create('garden-' . $this->gardens->allotment->getId())->consume(20);
        $crawler = $this->client->request('GET', $this->members);

        $this->client->submit($crawler->selectButton('Invite')->form(), ['invitation[email]' => 'frank@example.com']);

        self::assertResponseRedirects($this->members, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'Your garden has sent enough invitations today: retry tomorrow.');
        $this->client->submit($this->rowOf($crawler, 'erin@example.com')->selectButton('Send again')->form());
        self::assertEmailCount(0);
        self::assertSame([], self::getContainer()->get(InvitationRepository::class)->findBy(['email' => 'frank@example.com']));
        self::assertNotNull(self::getContainer()->get(InvitationRepository::class)->findOneByToken(TwoGardens::INVITATION_TOKEN), 'Not sent again either.');

        // A new link sends no email: not counted
        $this->client->submit($this->rowOf($crawler, 'erin@example.com')->selectButton('Copy a new link')->form());
        $this->client->followRedirect();
        self::assertSelectorExists('.invitation.is-shown input');

        // The others keep theirs
        $this->client->getCookieJar()->clear();
        $this->logIn($this->client, $this->gardens->carol);
        $crawler = $this->client->request('GET', '/gardens/' . $this->gardens->balcony->getId() . '/members');
        $this->client->submit($crawler->selectButton('Invite')->form(), ['invitation[email]' => 'frank@example.com']);
        self::assertEmailCount(1);
    }

    public function testOnlyItsOwnerInvites(): void
    {
        $this->client->getCookieJar()->clear();
        $this->logIn($this->client, $this->gardens->bob);

        $crawler = $this->client->request('GET', $this->members);
        self::assertResponseIsSuccessful('A member sees its members.');
        self::assertCount(0, $crawler->filter('form.invitation-form, .garden-invitations'));
        self::assertStringNotContainsString('erin@example.com', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/gardens/' . $this->gardens->allotment->getId() . '/invitations', ['invitation' => ['email' => 'frank@example.com']]);
        self::assertResponseStatusCodeSame(404);
        self::assertEmailCount(0);
    }

    /**
     * An accepted one is out of the list: a form left open says so.
     */
    public function testAnAcceptedInvitationIsNotSentAgain(): void
    {
        $crawler = $this->client->request('GET', $this->members);
        $form = $this->rowOf($crawler, 'erin@example.com')->selectButton('Send again')->form();
        $invitation = self::getContainer()->get(EntityManagerInterface::class)->find(Invitation::class, $this->gardens->invitation->getId()) ?? self::fail();
        $invitation->accept(UserFactory::new()->approved()->create(['displayName' => 'Erin']), new \DateTimeImmutable('2026-11-22 10:00'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->submit($form);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'The invitation of erin@example.com was accepted or revoked meanwhile.');
        self::assertEmailCount(0);
        InvitationFactory::assert()->count(1);
    }

    private function rowOf(Crawler $crawler, string $email): Crawler
    {
        return $crawler->filter('.invitation')->reduce(static fn (Crawler $row): bool => $row->filter('strong')->text() === $email)->first();
    }

    private function invitation(string $link): Invitation
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(InvitationRepository::class)->findOneByToken(substr($link, strrpos($link, '/') + 1)) ?? self::fail('The link finds no invitation.');
    }
}
