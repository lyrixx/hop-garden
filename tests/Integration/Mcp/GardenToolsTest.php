<?php

namespace App\Tests\Integration\Mcp;

use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Factory\SpaceFactory;
use App\Garden\Model\Soil;
use App\Garden\PlantingRepository;
use App\Garden\SpaceRepository;
use App\Plant\Model\Exposure;
use App\Tests\Utils\Mcp\McpTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The tools that propose to add to the garden write nothing: they give the card the user taps.
 */
final class GardenToolsTest extends McpTestCase
{
    public function testListSpaces(): void
    {
        $terrace = SpaceFactory::createOne(['name' => 'Terrace', 'exposure' => Exposure::PartShade, 'soil' => Soil::Loamy]);
        SpaceFactory::createOne(['name' => 'Balcony']);
        PlantingFactory::new()->of(PlantFactory::new()->validated()->create())->inSpace($terrace)->create();

        $result = $this->callTool('list_spaces');

        self::assertSame([
            ['id' => $result['spaces'][0]['id'], 'name' => 'Balcony', 'exposure' => null, 'soil' => null, 'plantings' => [], 'url' => 'https://hop-garden.test/g/the-garden-of-the-tests/spaces/balcony'],
            ['id' => $terrace->getId()->toRfc4122(), 'name' => 'Terrace', 'exposure' => 'part_shade', 'soil' => 'loamy', 'plantings' => ['Bigleaf hydrangea'], 'url' => 'https://hop-garden.test/g/the-garden-of-the-tests/spaces/terrace'],
        ], $result['spaces']);
    }

    public function testProposePlantingWritesNothing(): void
    {
        $plant = PlantFactory::new()->validated()->create();
        $terrace = SpaceFactory::createOne(['name' => 'Terrace']);

        $result = $this->callTool('propose_planting', ['plant_id' => $plant->getId()->toRfc4122(), 'name' => ' Hortensia ', 'space_id' => $terrace->getId()->toRfc4122()]);

        self::assertTrue(Uuid::isValid($result['proposal_id']));
        self::assertSame([$plant->getId()->toRfc4122(), $terrace->getId()->toRfc4122(), 'Hortensia'], [$result['plant_id'], $result['space_id'], $result['name']]);
        self::assertSame(
            \sprintf('https://hop-garden.test/g/the-garden-of-the-tests/proposals/plantings/%s?plant_id=%s&space_id=%s&name=Hortensia', $result['proposal_id'], $plant->getId()->toRfc4122(), $terrace->getId()->toRfc4122()),
            $result['validation_url'],
        );
        self::assertStringContainsString('Until they tap it', $result['next_step']);
        self::assertSame([], self::getContainer()->get(PlantingRepository::class)->findAll());
    }

    public function testProposePlantingWithoutASpace(): void
    {
        $plant = PlantFactory::new()->validated()->create();

        $result = $this->callTool('propose_planting', ['plant_id' => $plant->getId()->toRfc4122(), 'name' => 'Hortensia']);

        self::assertNull($result['space_id']);
        self::assertStringNotContainsString('space_id', $result['validation_url']);
    }

    public function testProposePlantingRefusesADraftAnUnknownPlantOrAnUnknownSpace(): void
    {
        $draft = PlantFactory::createOne();
        $validated = PlantFactory::new()->validated()->create();

        self::assertStringContainsString('is a draft: the user validates its card first', $this->callToolExpectingError('propose_planting', ['plant_id' => $draft->getId()->toRfc4122(), 'name' => 'Hortensia']));
        self::assertStringContainsString('No plant with id 01a205b0-0000-7000-8000-000000000000', $this->callToolExpectingError('propose_planting', ['plant_id' => '01a205b0-0000-7000-8000-000000000000', 'name' => 'Hortensia']));
        self::assertStringContainsString('No space with id 01a205b0-0000-7000-8000-000000000000: call list_spaces.', $this->callToolExpectingError('propose_planting', ['plant_id' => $validated->getId()->toRfc4122(), 'name' => 'Hortensia', 'space_id' => '01a205b0-0000-7000-8000-000000000000']));
    }

    public function testProposeSpaceWritesNothing(): void
    {
        $result = $this->callTool('propose_space', ['name' => ' Balcon ', 'exposure' => 'sun']);

        self::assertTrue(Uuid::isValid($result['proposal_id']));
        self::assertSame(['Balcon', 'sun', null], [$result['name'], $result['exposure'], $result['soil']]);
        self::assertSame(\sprintf('https://hop-garden.test/g/the-garden-of-the-tests/proposals/spaces/%s?name=Balcon&exposure=sun', $result['proposal_id']), $result['validation_url']);
        self::assertSame([], self::getContainer()->get(SpaceRepository::class)->findAll());
    }

    public function testProposeSpaceRefusesASpaceTheGardenHas(): void
    {
        $terrace = SpaceFactory::createOne(['name' => 'Terrace']);

        self::assertStringContainsString(
            \sprintf('The space "Terrace" exists already (id %s): propose the plant in it with propose_planting.', $terrace->getId()->toRfc4122()),
            $this->callToolExpectingError('propose_space', ['name' => 'terrace']),
        );
    }

    public function testOfferChoicesOnTheServerOfTheAgent(): void
    {
        $result = $this->callTool('offer_choices', ['question' => ' Where did you plant it? ', 'options' => ['Terrace ', 'A new space']], $this->mcp('/_mcp/agent'));

        self::assertSame(['Where did you plant it?', ['Terrace', 'A new space']], [$result['question'], $result['options']]);
        self::assertStringContainsString('Stop here', $result['next_step']);
    }
}
