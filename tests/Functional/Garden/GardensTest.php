<?php

namespace App\Tests\Functional\Garden;

use App\Factory\GardenFactory;
use App\Factory\PlantingFactory;
use App\Factory\UserFactory;
use App\Garden\GardenRepository;
use App\Garden\Model\MembershipRole;
use App\Tests\Utils\Security\LogInTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The gardens of a member: the wizard of the first one, its city found by the geocoding of Open-Meteo (recorded in
 * fixtures/http/open-meteo/geocoding/), the switch between them in the menu.
 */
final class GardensTest extends WebTestCase
{
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
            self::assertResponseRedirects('/gardens/new', 303, $page . ' needs a garden.');
        }
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful('Their account needs none.');

        $crawler = $this->client->request('GET', '/gardens/new');
        self::assertSelectorTextSame('h1', 'Your first garden');
        self::assertCount(0, $crawler->filter('input[name="garden[placeId]"]'), 'No place before a city.');

        // Paris has homonyms: one to pick
        $crawler = $this->client->submit($crawler->selectButton('Find')->form(), ['garden[name]' => 'The orchard', 'garden[city]' => 'Paris']);
        self::assertResponseStatusCodeSame(422, 'Shown again: Turbo Drive renders it in place.');
        self::assertSame(
            ['Paris, Île-de-France, France', 'Paris, Texas, United States', 'Paris (38242), Tennessee, United States'],
            $crawler->filter('.garden-places .choice span')->each(static fn (Crawler $label): string => $label->text()),
        );
        self::assertSame([], self::getContainer()->get(GardenRepository::class)->findOfMember($member), 'Searched only.');

        $form = $crawler->selectButton('Create the garden')->form();
        $form['garden[placeId]']->select('4717560');
        $this->client->submit($form);

        self::assertResponseRedirects('/assistant', 303);
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

        self::assertResponseRedirects('/assistant', 303, 'Created at once.');
    }

    public function testACityItDoesNotFindOrNoCityAtAll(): void
    {
        $this->logInWithoutGarden($this->client);
        $crawler = $this->client->request('GET', '/gardens/new');

        $crawler = $this->client->submit($crawler->selectButton('Find')->form(), ['garden[name]' => 'Balcony', 'garden[city]' => 'Atlantis']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.garden-city', 'No place found for "Atlantis"');

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

        $crawler = $this->client->request('GET', '/garden');
        self::assertSelectorTextContains('main', 'Apple tree', 'The one they joined first.');
        self::assertSelectorTextNotContains('main', 'Leek');
        self::assertSame(['The allotment'], $crawler->filter('.garden-switcher form button')->each(static fn (Crawler $button): string => $button->text()), 'The others, to switch to.');

        $this->client->submit($crawler->filter('.garden-switcher form')->form());
        self::assertResponseRedirects('/garden', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Leek');
        self::assertSelectorTextNotContains('main', 'Apple tree');
        self::assertSelectorNotExists('.account-menu a[href="/gardens/' . $second->getId() . '/settings"]', 'A member, not its owner.');

        $this->client->request('POST', '/gardens/' . GardenFactory::createOne()->getId() . '/switch', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(404, 'Not a garden of theirs, whatever the token.');
    }

    /**
     * A link to a plant of their other garden (a card of an external MCP client, bound to that garden) opens it there.
     */
    public function testAPlantOfTheirOtherGardenSwitchesToIt(): void
    {
        $member = UserFactory::new()->approved()->create();
        GardenFactory::new()->ownedBy($member)->create(['name' => 'The orchard']);
        $leek = PlantingFactory::createOne(['garden' => GardenFactory::new()->withMembers($member)->create(['name' => 'The allotment']), 'name' => 'Leek']);
        $this->logIn($this->client, $member);

        $this->client->request('GET', '/garden/' . $leek->getId());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/garden');
        self::assertSelectorTextContains('main', 'Leek', 'The allotment is current now.');
    }
}
