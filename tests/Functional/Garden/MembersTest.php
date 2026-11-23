<?php

namespace App\Tests\Functional\Garden;

use App\Account\Entity\User;
use App\Factory\UserFactory;
use App\Garden\Entity\Garden;
use App\Tests\Utils\Garden\TwoGardens;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The page of the members of a garden: each member sees them and may leave it, its owner removes a member. Its owner
 * leaves it to the oldest of the others (Garden::leave()); its only member does not leave it.
 */
final class MembersTest extends WebTestCase
{
    use LogInTrait;

    private KernelBrowser $client;
    private TwoGardens $gardens;
    private string $members;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->gardens = TwoGardens::create(self::getContainer());
        $this->members = '/gardens/' . $this->gardens->allotment->getId() . '/members';
    }

    public function testTheMenuLeadsToTheMembers(): void
    {
        $this->logIn($this->client, $this->gardens->bob);

        $crawler = $this->client->request('GET', '/garden');

        self::assertSame($this->members, $crawler->filter('.account-menu a.garden-name')->attr('href'));
        $crawler = $this->client->click($crawler->filter('.account-menu a.garden-name')->link());
        self::assertResponseIsSuccessful();
        self::assertSame([['Alice', 'Propriétaire'], ['Bob (vous)', 'Membre']], self::membersOf($crawler));
    }

    public function testTheOwnerRemovesAMember(): void
    {
        $this->logIn($this->client, $this->gardens->alice);
        $crawler = $this->client->request('GET', $this->members);
        $bob = self::rowOf($crawler, 'Bob');
        self::assertSame('Remove Bob from Allotment of Alice and Bob? They will no longer see its plants.', $bob->filter('form')->attr('data-turbo-confirm'));
        self::assertCount(0, self::rowOf($crawler, 'Alice')->filter('form[action$="/remove"]'), 'Never herself.');

        $this->client->submit($bob->selectButton('Remove')->form());

        self::assertResponseRedirects($this->members, 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Bob is no longer a member of Allotment of Alice and Bob.');
        self::assertSame([['Alice (you)', 'Owner']], self::membersOf($crawler));

        $this->as($this->gardens->bob);
        $this->client->request('GET', '/garden');
        self::assertResponseRedirects('/gardens/new', 303, 'No garden left: the wizard.');
        $this->client->request('GET', $this->members);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAMemberRemovesNobodyAndTheOwnerIsNeverRemoved(): void
    {
        $remove = $this->members . '/' . ($this->gardens->allotment->membershipOf($this->gardens->alice) ?? self::fail())->getId() . '/remove';

        $this->logIn($this->client, $this->gardens->bob);
        $crawler = $this->client->request('GET', $this->members);
        self::assertCount(0, $crawler->filter('form[action$="/remove"]'));
        $this->client->request('POST', $remove, ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(404);

        // Hers, with a valid token: she leaves it instead
        $this->as($this->gardens->alice);
        $crawler = $this->client->request('GET', $this->members);
        $this->client->request('POST', $remove, ['_token' => self::rowOf($crawler, 'Bob')->filter('input[name="_token"]')->attr('value')]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['Alice', 'Bob'], $this->memberNames());
    }

    public function testAMemberLeaves(): void
    {
        $this->logIn($this->client, $this->gardens->bob);
        $crawler = $this->client->request('GET', $this->members);
        $form = self::rowOf($crawler, 'Bob')->filter('form');
        self::assertSame('Quitter Allotment of Alice and Bob ? Vous ne verrez plus ses plantes.', str_replace("\u{a0}", ' ', (string) $form->attr('data-turbo-confirm')));

        $this->client->submit($form->form());

        self::assertResponseRedirects('/garden', 303);
        $this->client->followRedirect();
        self::assertResponseRedirects('/gardens/new', 303, 'His only garden: the wizard.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Vous avez quitté Allotment of Alice and Bob.');
        self::assertSame(['Alice'], $this->memberNames());
    }

    public function testTheOwnerLeavesItToTheOldestOfTheOthers(): void
    {
        $this->logIn($this->client, $this->gardens->alice);
        $crawler = $this->client->request('GET', $this->members);
        $form = self::rowOf($crawler, 'Alice')->filter('form');
        self::assertSame('Leave Allotment of Alice and Bob? Bob becomes its owner, and you will no longer see its plants.', $form->attr('data-turbo-confirm'));

        $this->client->submit($form->form());

        self::assertResponseRedirects('/garden', 303);
        self::assertSame(['Bob'], $this->memberNames());
        self::assertSame('Bob', $this->garden()->getOwner()->getDisplayName());
        $this->as($this->gardens->bob);
        $this->client->request('GET', '/gardens/' . $this->gardens->allotment->getId() . '/settings');
        self::assertResponseIsSuccessful('He manages it now.');
    }

    public function testItsOnlyMemberDoesNotLeaveIt(): void
    {
        $dan = UserFactory::new()->approved()->create(['displayName' => 'Dan']);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $balcony = $entityManager->find(Garden::class, $this->gardens->balcony->getId()) ?? self::fail();
        $balcony->join($dan, new \DateTimeImmutable('2026-11-02'));
        $entityManager->flush();
        $members = '/gardens/' . $balcony->getId() . '/members';
        $this->logIn($this->client, $this->gardens->carol);
        $form = self::rowOf($this->client->request('GET', $members), 'Carol')->selectButton('Leave the garden')->form();

        // Dan leaves meanwhile: her page is left open
        $balcony = $entityManager->find(Garden::class, $this->gardens->balcony->getId()) ?? self::fail();
        $balcony->leave($entityManager->find(User::class, $dan->getId()) ?? self::fail());
        $entityManager->flush();
        $this->client->submit($form);

        self::assertResponseRedirects($members, 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'You are the only member of Carol\'s balcony: it goes with your account if you delete it.');
        self::assertCount(0, $crawler->filter('form[action$="/leave"]'));
        self::assertSelectorTextContains('.garden-members .hint', 'You are its only member: invite somebody to garden with you.');
    }

    /**
     * Without joining the garden of the test: a member removed has none left.
     */
    private function as(User $member): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($member);
    }

    /**
     * @return list<array{string, string}> the name of each member, "(you)" after theirs, and their role
     */
    private static function membersOf(Crawler $crawler): array
    {
        return $crawler->filter('.member .member-name')->each(static fn (Crawler $member): array => [
            implode(' ', [$member->filter('strong')->text(), ...$member->filter('.member-you')->each(static fn (Crawler $you): string => $you->text())]),
            $member->filter('.tag')->text(),
        ]);
    }

    private static function rowOf(Crawler $crawler, string $name): Crawler
    {
        return $crawler->filter('.member')->reduce(static fn (Crawler $row): bool => $row->filter('.member-name strong')->text() === $name)->first();
    }

    /**
     * @return list<string>
     */
    private function memberNames(): array
    {
        return array_map(static fn (User $member): string => $member->getDisplayName(), $this->garden()->getMembers());
    }

    private function garden(): Garden
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(EntityManagerInterface::class)->find(Garden::class, $this->gardens->allotment->getId()) ?? self::fail('The garden is gone.');
    }
}
