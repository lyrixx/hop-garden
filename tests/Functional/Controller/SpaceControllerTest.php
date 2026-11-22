<?php

namespace App\Tests\Functional\Controller;

use App\Garden\Model\Planting;
use App\Garden\Model\Soil;
use App\Garden\Model\Space;
use App\Garden\SpaceRepository;
use App\Plant\Model\Exposure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class SpaceControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testTheSpacesWithTheirConditionsAndTheirPlants(): void
    {
        $northBed = $this->save(new Space('North bed', Exposure::Shade, Soil::Clay, new \DateTimeImmutable('2026-11-01')));
        $this->save(new Space('Balcony', null, null, new \DateTimeImmutable('2026-11-01')));
        $this->save(new Planting('Hosta', $northBed, null, new \DateTimeImmutable('2026-11-01')));
        $this->save(new Planting('Fern', $northBed, null, new \DateTimeImmutable('2026-11-01')));

        $crawler = $this->client->request('GET', '/spaces');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.nav a.active', 'My spaces');
        self::assertSame(
            [
                ['Balcony', 'Exposure and soil not recorded', 'No plant yet'],
                ['North bed', 'Shade Clay', 'Fern, Hosta'],
            ],
            $crawler->filter('.space-tile')->each(static fn ($tile): array => [$tile->filter('strong')->text(), $tile->filter('.tags')->text(), $tile->filter('.space-tile-plants')->text()]),
        );
        self::assertSame('/spaces/' . $northBed->getId(), $crawler->filter('.space-tile')->last()->attr('href'));
    }

    public function testNoSpaceYet(): void
    {
        $this->client->request('GET', '/spaces');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.callout', 'No space yet.');
    }

    public function testASpaceWithItsPlants(): void
    {
        $northBed = $this->save(new Space('North bed', Exposure::Shade, null, new \DateTimeImmutable('2026-11-01')));
        $hosta = $this->save(new Planting('Hosta', $northBed, null, new \DateTimeImmutable('2026-11-01')));

        $crawler = $this->client->request('GET', '/spaces/' . $northBed->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h3', 'North bed');
        self::assertSame(['Shade', 'Not recorded'], $crawler->filter('.care-sheet td')->each(static fn ($cell): string => $cell->text()));
        self::assertSame(['/garden/' . $hosta->getId()], $crawler->filter('.space-plants .plant-tile')->each(static fn ($tile): ?string => $tile->attr('href')));
    }

    public function testAnUnknownSpaceIsNotFound(): void
    {
        $this->client->request('GET', '/spaces/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAddASpace(): void
    {
        self::mockTime('2026-11-22 10:00');
        $crawler = $this->client->request('GET', '/spaces/new');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Add the space')->form(), [
            'space[name]' => '  Balcony ',
            'space[exposure]' => 'sun',
            'space[soil]' => 'loamy',
        ]);

        $space = $this->lastSpace();
        self::assertResponseRedirects('/spaces/' . $space->getId(), 303);
        self::assertSame('Balcony', $space->getName());
        self::assertSame(Exposure::Sun, $space->getExposure());
        self::assertSame(Soil::Loamy, $space->getSoil());
        self::assertSame('2026-11-22 10:00', $space->getCreatedAt()->format('Y-m-d H:i'));

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Balcony is one of your spaces.');
    }

    public function testASpaceNeedsAName(): void
    {
        $crawler = $this->client->request('GET', '/spaces/new');

        $this->client->submit($crawler->selectButton('Add the space')->form(), ['space[name]' => '  ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'This value should not be blank.');
        self::assertSame([], self::getContainer()->get(SpaceRepository::class)->findAll());
    }

    public function testEditASpace(): void
    {
        $balcony = $this->save(new Space('Balcony', Exposure::Sun, null, new \DateTimeImmutable('2026-11-01')));

        $crawler = $this->client->request('GET', '/spaces/' . $balcony->getId());
        $crawler = $this->client->click($crawler->selectLink('Edit the space')->link());
        self::assertResponseIsSuccessful();
        self::assertSame('Balcony', $crawler->filter('[name="space[name]"]')->attr('value'));
        self::assertSame('sun', $crawler->filter('[name="space[exposure]"]:checked')->attr('value'));

        $this->client->submit($crawler->selectButton('Save')->form(), [
            'space[name]' => 'South balcony',
            'space[exposure]' => '',
            'space[soil]' => 'sandy',
        ]);

        self::assertResponseRedirects('/spaces/' . $balcony->getId(), 303);
        $balcony = $this->lastSpace();
        self::assertSame('South balcony', $balcony->getName());
        self::assertNull($balcony->getExposure(), 'Not sure any more.');
        self::assertSame(Soil::Sandy, $balcony->getSoil());
    }

    public function testAnInvalidEditIsRefused(): void
    {
        $balcony = $this->save(new Space('Balcony', Exposure::Sun, null, new \DateTimeImmutable('2026-11-01')));
        $crawler = $this->client->request('GET', '/spaces/' . $balcony->getId() . '/edit');

        $this->client->submit($crawler->selectButton('Save')->form(), ['space[name]' => str_repeat('a', 101)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Balcony', $this->lastSpace()->getName());
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function save(object $entity): object
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($entity);
        $entityManager->flush();

        return $entity;
    }

    private function lastSpace(): Space
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(SpaceRepository::class)->findOneBy([], ['id' => 'DESC']) ?? self::fail('No space was saved.');
    }
}
