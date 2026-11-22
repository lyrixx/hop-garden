<?php

namespace App\Tests\Functional\Controller;

use App\Factory\PlantFactory;
use App\Factory\SpaceFactory;
use App\Garden\Entity\Space;
use App\Garden\Model\Soil;
use App\Garden\PlantingRepository;
use App\Garden\SpaceRepository;
use App\Plant\Entity\Plant;
use App\Plant\Model\Exposure;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * The cards the agent proposes: nothing is written until a tap, which writes once.
 */
final class ProposalControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAPlantingCardInItsFrame(): void
    {
        [$url, $frame] = $this->plantingCard();

        $crawler = $this->client->request('GET', $url, server: ['HTTP_TURBO_FRAME' => $frame]);

        self::assertResponseIsSuccessful();
        self::assertSame($frame, $crawler->filter('turbo-frame')->attr('id'));
        self::assertCount(0, $crawler->filter('nav'), 'The card alone, without the layout.');
        self::assertSame('Add to my garden', $crawler->filter('.kicker')->text());
        self::assertSame('Hortensia', $crawler->filter('input[name$="[name]"]')->attr('value'));
        self::assertStringContainsString('Hydrangea macrophylla', $crawler->filter('.proposal-facts')->text());
        self::assertStringContainsString('Terrace', $crawler->filter('.proposal-facts')->text());
        self::assertCount(0, $crawler->filter('[data-chat-notice]'), 'Nothing to tell the agent before the tap.');
    }

    public function testAPlantingCardAsAPage(): void
    {
        [$url] = $this->plantingCard();

        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav', 'The URL of an external MCP client: a page of its own.');
        self::assertCount(1, $crawler->filter('article.proposal-card form'));
    }

    public function testATapAddsThePlantOnce(): void
    {
        [$url, $frame] = $this->plantingCard();
        $form = $this->client->request('GET', $url, server: ['HTTP_TURBO_FRAME' => $frame])->selectButton('Add to my garden')->form();
        $name = $form->getName() . '[name]';

        $this->client->submit($form, [$name => '  Hortensia bleu '], ['HTTP_TURBO_FRAME' => $frame]);
        self::assertResponseStatusCodeSame(303);
        $added = $this->client->followRedirect();

        $plantings = $this->plantings()->findAll();
        self::assertCount(1, $plantings);
        self::assertSame('Hortensia bleu', $plantings[0]->getName());
        self::assertSame('Terrace', $plantings[0]->getSpace()?->getName());
        self::assertSame('Hydrangea macrophylla', $plantings[0]->getPlant()?->getScientificName());
        self::assertSame($frame, $added->filter('turbo-frame')->attr('id'));
        self::assertSame('Added', $added->filter('.status')->text());
        self::assertSame('/garden/' . $plantings[0]->getId(), $added->filter('a.button-next')->attr('href'));
        self::assertSame('I added Hortensia bleu to my garden, in Terrace.', $added->filter('[data-chat-notice]')->attr('data-chat-notice'));

        // A double tap, or the same card in a second tab
        $this->client->submit($form, [$name => 'Hortensia bleu'], ['HTTP_TURBO_FRAME' => $frame]);
        self::assertResponseStatusCodeSame(303);
        self::assertCount(1, $this->plantings()->findAll());

        $reloaded = $this->client->request('GET', $url, server: ['HTTP_TURBO_FRAME' => $frame]);
        self::assertSame('Added', $reloaded->filter('.status')->text(), 'Added, after a reload too.');
        self::assertCount(0, $reloaded->filter('form'));
    }

    public function testAPlantWithoutASpace(): void
    {
        $plant = PlantFactory::new()->validated()->create();
        $url = $this->plantingUrl(['plant_id' => $plant->getId(), 'name' => 'Hortensia']);

        $crawler = $this->client->request('GET', $url);
        self::assertStringContainsString('Not in a space yet', $crawler->filter('.proposal-facts')->text());
        $this->client->submit($crawler->selectButton('Add to my garden')->form());

        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->plantings()->findAll()[0]->getSpace());
    }

    public function testABlankNameIsRefused(): void
    {
        [$url, $frame] = $this->plantingCard();
        $form = $this->client->request('GET', $url, server: ['HTTP_TURBO_FRAME' => $frame])->selectButton('Add to my garden')->form();

        $crawler = $this->client->submit($form, [$form->getName() . '[name]' => '  '], ['HTTP_TURBO_FRAME' => $frame]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($frame, $crawler->filter('turbo-frame')->attr('id'), 'In its frame.');
        self::assertCount(1, $crawler->filter('form'));
        self::assertSame([], $this->plantings()->findAll());
    }

    public function testADraftSpeciesIsNotAddedYet(): void
    {
        $plant = PlantFactory::createOne();
        $url = $this->plantingUrl(['plant_id' => $plant->getId(), 'name' => 'Hortensia']);

        $crawler = $this->client->request('GET', $url);

        self::assertSame('Validate the care sheet of this species first.', $crawler->filter('.proposal-card .warning')->text());
        self::assertCount(0, $crawler->filter('.proposal-card form'));
    }

    #[DataProvider('provideACardOfSomethingDeletedSinceItShowedCases')]
    public function testACardOfSomethingDeletedSinceItShowed(string $deleted): void
    {
        [$url, $frame, $plant, $space] = $this->plantingCard();
        $form = $this->client->request('GET', $url, server: ['HTTP_TURBO_FRAME' => $frame])->selectButton('Add to my garden')->form();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->remove('plant' === $deleted ? $entityManager->find(Plant::class, $plant->getId()) : $entityManager->find(Space::class, $space->getId()));
        $entityManager->flush();

        $crawler = $this->client->submit($form, [], ['HTTP_TURBO_FRAME' => $frame]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('no longer exists. Ask the assistant again.', $crawler->filter('.proposal-card .warning')->text());
        self::assertSame([], $this->plantings()->findAll());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideACardOfSomethingDeletedSinceItShowedCases(): iterable
    {
        yield 'a deleted care sheet' => ['plant'];
        yield 'a deleted space' => ['space'];
    }

    public function testACardIsKeyedByAUuid(): void
    {
        $this->client->request('GET', '/proposals/plantings/not-a-uuid?plant_id=1&name=Hortensia');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/proposals/spaces/not-a-uuid?name=Balcon');
        self::assertResponseStatusCodeSame(404);
    }

    public function testASpaceCardCreatesTheSpaceOnce(): void
    {
        $url = $this->spaceUrl(['name' => 'Balcon']);
        $frame = 'space-proposal-' . self::proposalIdOf($url);

        $crawler = $this->client->request('GET', $url, server: ['HTTP_TURBO_FRAME' => $frame]);
        self::assertResponseIsSuccessful();
        self::assertSame('New space', $crawler->filter('.kicker')->text());
        $form = $crawler->selectButton('Create the space')->form();
        self::assertSame('Balcon', $form[$form->getName() . '[name]']->getValue(), 'Its name prefilled.');
        self::assertSame('', $form[$form->getName() . '[exposure]']->getValue(), 'Its exposure to tap.');

        $values = [$form->getName() . '[exposure]' => 'sun', $form->getName() . '[soil]' => 'sandy'];
        $this->client->submit($form, $values, ['HTTP_TURBO_FRAME' => $frame]);
        self::assertResponseStatusCodeSame(303);
        $created = $this->client->followRedirect();

        $spaces = $this->spaces()->findAll();
        self::assertCount(1, $spaces);
        self::assertSame(['Balcon', Exposure::Sun, Soil::Sandy], [$spaces[0]->getName(), $spaces[0]->getExposure(), $spaces[0]->getSoil()]);
        self::assertSame('Created', $created->filter('.status')->text());
        self::assertSame('/spaces/' . $spaces[0]->getId(), $created->filter('a.button-next')->attr('href'));
        self::assertSame('I created the space Balcon.', $created->filter('[data-chat-notice]')->attr('data-chat-notice'));

        $this->client->submit($form, $values, ['HTTP_TURBO_FRAME' => $frame]);
        self::assertResponseStatusCodeSame(303);
        self::assertCount(1, $this->spaces()->findAll());
        self::assertSame('Created', $this->client->request('GET', $url, server: ['HTTP_TURBO_FRAME' => $frame])->filter('.status')->text(), 'Created, after a reload too.');
    }

    public function testASpaceCardWithTheExposureAndTheSoilTheUserSaid(): void
    {
        $crawler = $this->client->request('GET', $this->spaceUrl(['name' => 'Balcon', 'exposure' => 'shade', 'soil' => 'clay']));

        $form = $crawler->selectButton('Create the space')->form();
        self::assertSame('shade', $form[$form->getName() . '[exposure]']->getValue());
        self::assertSame('clay', $form[$form->getName() . '[soil]']->getValue());
    }

    public function testASpaceCardRefusesAName(): void
    {
        SpaceFactory::createOne(['name' => 'Balcon']);
        $url = $this->spaceUrl(['name' => 'Balcon']);
        $frame = 'space-proposal-' . self::proposalIdOf($url);
        $form = $this->client->request('GET', $url, server: ['HTTP_TURBO_FRAME' => $frame])->selectButton('Create the space')->form();

        // Another card proposed it, and the user tapped it
        $crawler = $this->client->submit($form, [$form->getName() . '[name]' => 'balcon'], ['HTTP_TURBO_FRAME' => $frame]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The garden has a space named "Balcon" already.', $crawler->filter('form')->text());

        $crawler = $this->client->submit($form, [$form->getName() . '[name]' => ''], ['HTTP_TURBO_FRAME' => $frame]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($frame, $crawler->filter('turbo-frame')->attr('id'));
        self::assertCount(1, $this->spaces()->findAll());
    }

    /**
     * An "Add to my garden" card of a validated hydrangea, on the terrace.
     *
     * @return array{string, string, Plant, Space} its URL, the id of its frame, its species, its space
     */
    private function plantingCard(): array
    {
        $plant = PlantFactory::new()->validated()->create();
        $space = SpaceFactory::createOne(['name' => 'Terrace', 'exposure' => Exposure::PartShade, 'soil' => Soil::Loamy]);
        $url = $this->plantingUrl(['plant_id' => $plant->getId(), 'space_id' => $space->getId(), 'name' => 'Hortensia']);

        return [$url, 'planting-proposal-' . self::proposalIdOf($url), $plant, $space];
    }

    /**
     * @param array<string, int|string> $query
     */
    private function plantingUrl(array $query): string
    {
        return '/proposals/plantings/' . (new UuidV7())->toRfc4122() . '?' . http_build_query($query);
    }

    /**
     * @param array<string, string> $query
     */
    private function spaceUrl(array $query): string
    {
        return '/proposals/spaces/' . (new UuidV7())->toRfc4122() . '?' . http_build_query($query);
    }

    /**
     * The proposal id in the URL of a card.
     */
    private static function proposalIdOf(string $url): string
    {
        self::assertSame(1, preg_match('#/([0-9a-f-]{36})\?#', $url, $matches));

        return $matches[1];
    }

    private function plantings(): PlantingRepository
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(PlantingRepository::class);
    }

    private function spaces(): SpaceRepository
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(SpaceRepository::class);
    }
}
