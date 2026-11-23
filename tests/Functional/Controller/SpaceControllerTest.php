<?php

namespace App\Tests\Functional\Controller;

use App\Factory\GardenFactory;
use App\Factory\PlantingFactory;
use App\Factory\SpaceFactory;
use App\Garden\Entity\Space;
use App\Garden\Model\Soil;
use App\Garden\SpaceRepository;
use App\Plant\Model\Exposure;
use App\Tests\Utils\Garden\GardenPathsTrait;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class SpaceControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use GardenPathsTrait;
    use LogInTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->logIn($this->client);
    }

    public function testTheSpacesWithTheirConditionsAndTheirPlants(): void
    {
        $northBed = SpaceFactory::createOne(['name' => 'North bed', 'exposure' => Exposure::Shade, 'soil' => Soil::Clay]);
        SpaceFactory::createOne(['name' => 'Balcony']);
        PlantingFactory::new()->inSpace($northBed)->create(['name' => 'Hosta']);
        PlantingFactory::new()->inSpace($northBed)->create(['name' => 'Fern']);

        $crawler = $this->client->request('GET', self::inGarden('/spaces'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.nav a.active', 'My spaces');
        self::assertSame(
            [
                ['Balcony', 'Exposure and soil not recorded', 'No plant yet'],
                ['North bed', 'Shade Clay', 'Fern, Hosta'],
            ],
            $crawler->filter('.space-tile')->each(static fn ($tile): array => [$tile->filter('strong')->text(), $tile->filter('.tags')->text(), $tile->filter('.space-tile-plants')->text()]),
        );
        self::assertSame(self::pathOf($northBed), $crawler->filter('.space-tile')->last()->attr('href'));
    }

    public function testNoSpaceYet(): void
    {
        $this->client->request('GET', self::inGarden('/spaces'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.callout', 'No space yet.');
    }

    public function testASpaceWithItsPlants(): void
    {
        $northBed = SpaceFactory::createOne(['name' => 'North bed', 'exposure' => Exposure::Shade]);
        $hosta = PlantingFactory::new()->inSpace($northBed)->create(['name' => 'Hosta']);

        $crawler = $this->client->request('GET', self::pathOf($northBed));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h3', 'North bed');
        self::assertSame(['Shade', 'Not recorded'], $crawler->filter('.care-sheet td')->each(static fn ($cell): string => $cell->text()));
        self::assertSame([self::pathOf($hosta)], $crawler->filter('.space-plants .plant-tile')->each(static fn ($tile): ?string => $tile->attr('href')));
    }

    public function testAnUnknownSpaceIsNotFound(): void
    {
        $this->client->request('GET', self::inGarden('/spaces/no-such-space'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAddASpace(): void
    {
        self::mockTime('2026-11-22 10:00');
        $crawler = $this->client->request('GET', self::inGarden('/spaces/new'));
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Add the space')->form(), [
            'space[name]' => '  Balcony ',
            'space[exposure]' => 'sun',
            'space[soil]' => 'loamy',
        ]);

        $space = $this->lastSpace();
        self::assertResponseRedirects(self::pathOf($space), 303);
        self::assertSame('Balcony', $space->getName());
        self::assertSame(Exposure::Sun, $space->getExposure());
        self::assertSame(Soil::Loamy, $space->getSoil());
        self::assertSame('2026-11-22 10:00', $space->getCreatedAt()->format('Y-m-d H:i'));

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Balcony is one of your spaces.');
    }

    /**
     * From its name, in its garden: suffixed when taken, or when a page of the spaces has it; without a letter, "space".
     */
    #[TestWith(['Balcony!', 'balcony-2'])]
    #[TestWith(['New', 'new-2'])]
    #[TestWith(['Été', 'ete'])]
    #[TestWith(['🌹', 'space'])]
    public function testTheSlugOfANewSpace(string $name, string $slug): void
    {
        SpaceFactory::createOne(['name' => 'balcony']);
        SpaceFactory::createOne(['garden' => GardenFactory::createOne(), 'name' => 'Été']);
        $crawler = $this->client->request('GET', self::inGarden('/spaces/new'));

        $this->client->submit($crawler->selectButton('Add the space')->form(), ['space[name]' => $name]);

        self::assertSame($slug, $this->lastSpace()->getSlug(), 'Unique in its garden only.');
        self::assertResponseRedirects(self::inGarden('/spaces/' . $slug), 303);
    }

    public function testASpaceNeedsAName(): void
    {
        $crawler = $this->client->request('GET', self::inGarden('/spaces/new'));

        $this->client->submit($crawler->selectButton('Add the space')->form(), ['space[name]' => '  ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'This value should not be blank.');
        self::assertSame([], self::getContainer()->get(SpaceRepository::class)->findAll());
    }

    public function testEditASpace(): void
    {
        $balcony = SpaceFactory::createOne(['name' => 'Balcony', 'exposure' => Exposure::Sun]);

        $crawler = $this->client->request('GET', self::pathOf($balcony));
        $crawler = $this->client->click($crawler->selectLink('Edit the space')->link());
        self::assertResponseIsSuccessful();
        self::assertSame('Balcony', $crawler->filter('[name="space[name]"]')->attr('value'));
        self::assertSame('sun', $crawler->filter('[name="space[exposure]"]:checked')->attr('value'));

        $this->client->submit($crawler->selectButton('Save')->form(), [
            'space[name]' => 'South balcony',
            'space[exposure]' => '',
            'space[soil]' => 'sandy',
        ]);

        self::assertResponseRedirects(self::pathOf($balcony), 303);
        $balcony = $this->lastSpace();
        self::assertSame('South balcony', $balcony->getName());
        self::assertNull($balcony->getExposure(), 'Not sure any more.');
        self::assertSame(Soil::Sandy, $balcony->getSoil());
    }

    public function testAnInvalidEditIsRefused(): void
    {
        $balcony = SpaceFactory::createOne(['name' => 'Balcony', 'exposure' => Exposure::Sun]);
        $crawler = $this->client->request('GET', self::pathOf($balcony) . '/edit');

        $this->client->submit($crawler->selectButton('Save')->form(), ['space[name]' => str_repeat('a', 101)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Balcony', $this->lastSpace()->getName());
    }

    private function lastSpace(): Space
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(SpaceRepository::class)->findOneBy([], ['id' => 'DESC']) ?? self::fail('No space was saved.');
    }
}
