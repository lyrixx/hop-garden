<?php

namespace App\Tests\Functional\Garden;

use App\Factory\GardenFactory;
use App\Factory\PlantingFactory;
use App\Factory\UserFactory;
use App\Garden\GardenRepository;
use App\Garden\Model\MembershipRole;
use App\Tests\Utils\Garden\GardenPathsTrait;
use App\Tests\Utils\Security\LogInTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The gardens of a member: the wizard of the first one, its city found by the geocoding of Open-Meteo (recorded in
 * fixtures/http/open-meteo/geocoding/), each under its URL (/g/{garden}), the switch between them in the menu.
 */
final class GardensTest extends WebTestCase
{
    use GardenPathsTrait;
    use LogInTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testANewMemberMakesTheirFirstGardenInTheWizard(): void
    {
        $member = $this->logInWithoutGarden($this->client, UserFactory::new()->approved()->create(['displayName' => 'Dave']));

        foreach (['/assistant', '/garden', '/plants', '/spaces', '/notifications'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseRedirects('/gardens/new', 303, $page . ', of the last garden, needs a garden.');
        }
        $crawler = $this->client->request('GET', '/account');
        self::assertSame('/gardens/new', $crawler->filter('.nav a')->first()->attr('href'), 'The navigation leads to the wizard.');
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful('Their account needs none.');

        $crawler = $this->client->request('GET', '/gardens/new');
        self::assertSelectorTextSame('h1', 'Your first garden');
        self::assertCount(0, $crawler->filter('input[name="garden[placeId]"]'), 'No place before a city.');

        // Paris has homonyms: one to pick
        $crawler = $this->client->submit($crawler->selectButton('Find')->form(), ['garden[name]' => 'The orchard', 'garden[city]' => 'Paris']);
        self::assertResponseStatusCodeSame(422, 'Shown again: Turbo Drive renders it in place.');
        self::assertSame(
            ['Paris, Île-de-France, France', 'Paris, Texas, United States', 'Paris, Tennessee, United States'],
            $crawler->filter('.garden-places .choice span')->each(static fn (Crawler $label): string => $label->text()),
        );
        self::assertSame([], self::getContainer()->get(GardenRepository::class)->findOfMember($member), 'Searched only.');

        $form = $crawler->selectButton('Create the garden')->form();
        $form['garden[placeId]']->select('4717560');
        $this->client->submit($form);

        self::assertResponseRedirects('/g/the-orchard/assistant', 303, 'Its assistant, under its slug.');
        $gardens = self::getContainer()->get(GardenRepository::class)->findOfMember($member);
        self::assertCount(1, $gardens);
        self::assertSame('The orchard', $gardens[0]->getName());
        self::assertSame(['Paris, Texas, United States', 33.66094, -95.55551, 'America/Chicago'], [$gardens[0]->getLocation()->getPlaceName(), $gardens[0]->getLocation()->getLatitude(), $gardens[0]->getLocation()->getLongitude(), $gardens[0]->getLocation()->getTimezone()->getName()]);
        self::assertSame([[$member->getId(), MembershipRole::Owner]], array_map(static fn ($membership): array => [$membership->getUser()->getId(), $membership->getRole()], $gardens[0]->getMemberships()));

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash-success', 'The orchard is ready.');
        self::assertSelectorTextContains('.account-menu .garden-name', 'The orchard');
    }

    public function testTheOnePlaceOfACityIsPickedAlready(): void
    {
        $this->logInWithoutGarden($this->client);
        $crawler = $this->client->request('GET', '/gardens/new');

        $this->client->submit($crawler->selectButton('Create the garden')->form(), ['garden[name]' => 'Balcony', 'garden[city]' => 'Lyon']);

        self::assertResponseRedirects('/g/balcony/assistant', 303, 'Created at once.');
    }

    public function testACityItDoesNotFindOrNoCityAtAll(): void
    {
        $this->logInWithoutGarden($this->client);
        $crawler = $this->client->request('GET', '/gardens/new');

        $crawler = $this->client->submit($crawler->selectButton('Find')->form(), ['garden[name]' => 'Balcony', 'garden[city]' => 'Atlantis']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.garden-city', 'No place named "Atlantis"');

        $this->client->submit($crawler->selectButton('Create the garden')->form(), ['garden[name]' => 'Balcony', 'garden[city]' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.garden-city', 'Search the city of your garden first.');
    }

    public function testAMemberOfSeveralGardensSwitchesBetweenThem(): void
    {
        $member = UserFactory::new()->approved()->create();
        $first = GardenFactory::new()->ownedBy($member)->create(['name' => 'The orchard']);
        $second = GardenFactory::new()->withMembers($member)->create(['name' => 'The allotment']);
        PlantingFactory::createOne(['garden' => $first, 'name' => 'Apple tree']);
        PlantingFactory::createOne(['garden' => $second, 'name' => 'Leek']);
        $this->logIn($this->client, $member);

        $this->client->request('GET', '/garden');
        self::assertResponseRedirects('/g/the-orchard/garden', 302, 'The one they joined first.');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Apple tree');
        self::assertSelectorTextNotContains('main', 'Leek');
        self::assertSame(['The allotment' => '/g/the-allotment/garden'], self::switcher($crawler), 'The others, at the same page.');

        $crawler = $this->client->click($crawler->filter('.garden-switcher')->selectLink('The allotment')->link());
        self::assertSelectorTextContains('main', 'Leek');
        self::assertSelectorTextNotContains('main', 'Apple tree');
        self::assertSelectorNotExists('.account-menu a[href="/g/the-allotment/settings"]', 'A member, not its owner.');
        self::assertSame(['The orchard' => '/g/the-orchard/garden'], self::switcher($crawler));

        // A page of one of its resources, or the settings of the garden of its owner: the assistant of the other
        $crawler = $this->client->request('GET', self::pathOf(PlantingFactory::createOne(['garden' => $second, 'name' => 'Onion'])));
        self::assertSame(['The orchard' => '/g/the-orchard/assistant'], self::switcher($crawler));
        $crawler = $this->client->request('GET', '/g/the-orchard/settings');
        self::assertSame(['The allotment' => '/g/the-allotment/assistant'], self::switcher($crawler), 'They do not manage the allotment.');

        $this->client->request('GET', '/assistant');
        self::assertResponseRedirects('/g/the-orchard/assistant', 302, 'The last one.');

        $this->client->request('GET', self::inGarden('/garden', GardenFactory::createOne(['name' => 'Not theirs'])));
        self::assertResponseStatusCodeSame(404, 'Not a garden of theirs.');
    }

    /**
     * A link to a plant of their other garden (a card of an external MCP client, bound to that garden) opens it there,
     * which is their last garden then.
     */
    public function testAPlantOfTheirOtherGardenOpensInIt(): void
    {
        $member = UserFactory::new()->approved()->create();
        GardenFactory::new()->ownedBy($member)->create(['name' => 'The orchard']);
        $leek = PlantingFactory::createOne(['garden' => GardenFactory::new()->withMembers($member)->create(['name' => 'The allotment']), 'name' => 'Leek']);
        $this->logIn($this->client, $member);

        $this->client->request('GET', self::pathOf($leek));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.garden-switcher summary', 'The allotment');
        $this->client->request('GET', '/garden');
        self::assertResponseRedirects('/g/the-allotment/garden', 302, 'The last one.');
    }

    /**
     * @return array<string, string> the other gardens of the switcher, and where each leads
     */
    private static function switcher(Crawler $crawler): array
    {
        $links = $crawler->filter('.garden-switcher-list a')->reduce(static fn (Crawler $link): bool => '/gardens/new' !== $link->attr('href'));

        return array_combine($links->each(static fn (Crawler $link): string => $link->text()), $links->each(static fn (Crawler $link): string => (string) $link->attr('href')));
    }
}
