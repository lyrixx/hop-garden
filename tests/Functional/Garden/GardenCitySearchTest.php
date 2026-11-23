<?php

namespace App\Tests\Functional\Garden;

use App\Garden\GardenRepository;
use App\Tests\Utils\Security\LogInTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The city of a new garden, by its name, its postcode, or both: the recorded responses of the demo mode
 * (fixtures/http/open-meteo/geocoding/), and the cities they know when they know none.
 */
final class GardenCitySearchTest extends WebTestCase
{
    use LogInTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testACityByItsName(): void
    {
        $member = $this->logInWithoutGarden($this->client);
        $crawler = $this->client->request('GET', '/gardens/new');

        $crawler = $this->client->submit($crawler->selectButton('Find')->form(), ['garden[name]' => 'The orchard', 'garden[city]' => 'Paron']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['Paron (89100), Bourgogne-Franche-Comté, France', 'Paron (72122), Arkansas, United States', 'Parón, Ancash, Peru', 'Parona, Lombardy, Italy', 'Paron, Kirov Oblast, Russia'],
            self::suggestions($crawler),
            'Its homonyms, told apart by their region, their country and their postcode.',
        );

        $form = $crawler->selectButton('Create the garden')->form();
        $form['garden[placeId]']->select('2988475');
        $this->client->submit($form);

        self::assertResponseRedirects('/assistant', 303);
        $location = self::getContainer()->get(GardenRepository::class)->findOfMember($member)[0]->getLocation();
        self::assertSame(['Paron, Bourgogne-Franche-Comté, France', 48.17888, 3.25075, 'Europe/Paris'], [$location->getPlaceName(), $location->getLatitude(), $location->getLongitude(), $location->getTimezone()->getName()]);
    }

    public function testACityByItsPostcodeAndItsNameInAnyOrder(): void
    {
        foreach (['89100 Paron', 'Paron 89100'] as $city) {
            $member = $this->logInWithoutGarden($this->client);
            $crawler = $this->client->request('GET', '/gardens/new');

            $this->client->submit($crawler->selectButton('Create the garden')->form(), ['garden[name]' => 'The orchard', 'garden[city]' => $city]);

            self::assertResponseRedirects('/assistant', 303, $city . ': the one place of its postcode and its name, picked already.');
            self::assertSame('Paron, Bourgogne-Franche-Comté, France', self::getContainer()->get(GardenRepository::class)->findOfMember($member)[0]->getLocation()->getPlaceName());
        }
    }

    public function testACityByItsPostcode(): void
    {
        $this->logInWithoutGarden($this->client);
        $crawler = $this->client->request('GET', '/gardens/new');

        $crawler = $this->client->submit($crawler->selectButton('Find')->form(), ['garden[name]' => 'The orchard', 'garden[city]' => '89100']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['Sens (89100)', 'Paron (89100)', 'Saint-Clément (89100)', 'Malay-le-Grand (89100)', 'Saint-Martin-du-Tertre (89100)'],
            array_map(static fn (string $suggestion): string => explode(',', $suggestion)[0], self::suggestions($crawler)),
            'The most populated first.',
        );
    }

    public function testInDemoModeACityNotRecordedGetsTheOnesThatAre(): void
    {
        $this->logInWithoutGarden($this->client);
        $crawler = $this->client->request('GET', '/gardens/new');

        $this->client->submit($crawler->selectButton('Find')->form(), ['garden[name]' => 'Balcony', 'garden[city]' => 'Atlantis']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.garden-city', 'No place found for "Atlantis"');
        self::assertSelectorTextContains('.garden-city', 'In demo mode, the search answers a few recorded places only. Try: Auxerre, Bordeaux, Lille, London, Lyon, Nantes, Paris, Paron, 89000, 89100.');
    }

    /**
     * @return list<string>
     */
    private static function suggestions(Crawler $crawler): array
    {
        return $crawler->filter('.garden-places .choice span')->each(static fn (Crawler $label): string => $label->text());
    }
}
