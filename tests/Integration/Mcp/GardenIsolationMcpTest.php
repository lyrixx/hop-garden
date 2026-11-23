<?php

namespace App\Tests\Integration\Mcp;

use App\Factory\GardenFactory;
use App\Plant\Model\PlantStatus;
use App\Plant\PlantRepository;
use App\Reminder\Model\ReminderType;
use App\Tests\Utils\Garden\TwoGardens;
use App\Tests\Utils\Mcp\McpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Client;
use Mcp\Exception\RequestException;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Tool;

/**
 * Every tool of both MCP servers, called by Carol (the agent of her chat, an external client of hers) with the UUIDs of
 * the resources of the garden of Alice and Bob: the same error as for a UUID that does not exist, or her garden only; a
 * malformed id is refused by the schema of the tool. A tool this test does not know makes it fail. The routes of the
 * website: GardenIsolationTest.
 */
final class GardenIsolationMcpTest extends McpTestCase
{
    private const string GHOST = '01a205b0-0000-7000-8000-000000000000';
    private const string MALFORMED = '42';

    private TwoGardens $gardens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gardens = TwoGardens::create(self::getContainer());
    }

    public function testEveryToolOfBothServersIsCovered(): void
    {
        $tools = array_values(array_unique(array_map(static fn (Tool $tool): string => $tool->name, [...$this->agent()->listTools()->tools, ...$this->external()->listTools()->tools])));
        sort($tools);
        $covered = array_keys($this->attempts());
        sort($covered);

        self::assertSame($covered, $tools, 'A new tool: say what Carol may do with it.');
    }

    public function testTheToolsOfHerRunsSeeHerGardenOnly(): void
    {
        foreach ([$this->agent(), $this->external()] as $client) {
            $tools = array_map(static fn (Tool $tool): string => $tool->name, $client->listTools()->tools);
            foreach ($this->attempts() as $tool => $attempts) {
                if (!\in_array($tool, $tools, true)) {
                    continue;
                }

                foreach ($attempts as [$arguments, $expectation]) {
                    match ($expectation) {
                        'gone' => $this->assertGone($client, $tool, $arguments),
                        'hers' => $this->assertHers($client, $tool, $arguments),
                    };
                }
            }
        }

        $this->assertTheirGardenIsUntouched();
    }

    public function testTheResourcesOfTheExternalServerToo(): void
    {
        $collection = $this->external()->readResource('hopgarden://collection')->contents[0];
        self::assertInstanceOf(TextResourceContents::class, $collection);
        self::assertStringContainsString('Lavender of Carol', $collection->text);
        self::assertNoNameOfTheirs($collection->text, 'hopgarden://collection');

        $rose = $this->gardens->rose->getId()->toRfc4122();
        self::assertSame($this->readError('hopgarden://plants/' . self::GHOST), str_replace($rose, self::GHOST, $this->readError('hopgarden://plants/' . $rose)));
        self::assertSame($this->readError('hopgarden://plants/' . self::GHOST), str_replace(self::MALFORMED, self::GHOST, $this->readError('hopgarden://plants/' . self::MALFORMED)));
    }

    /**
     * Bound to a garden, whatever the others of its member: Bob, a member of a second garden, in a run of that one.
     */
    public function testATokenIsBoundToOneGardenOfItsMember(): void
    {
        $bobsOther = GardenFactory::new()->ownedBy($this->gardens->bob)->create(['name' => 'Garden of Bob alone']);
        $client = $this->mcp('/_mcp/agent', $this->gardens->bob, $bobsOther);

        self::assertSame([], $this->callTool('list_collection', client: $client)['plants']);
        $this->assertGone($client, 'get_plant', ['id' => $this->gardens->rose->getId()->toRfc4122()]);
    }

    /**
     * @return array<string, list<array{array<string, mixed>, 'gone'|'hers'}>> by tool, what Carol tries
     */
    private function attempts(): array
    {
        $rose = $this->gardens->rose->getId()->toRfc4122();
        $draft = $this->gardens->draft->getId()->toRfc4122();

        return [
            'confirm_plant' => [[['id' => $draft], 'gone'], [['id' => $rose], 'gone']],
            'disable_reminder' => [[['plant_id' => $rose, 'type' => 'pruning'], 'gone']],
            'enable_reminder' => [[['plant_id' => $rose, 'type' => 'deadheading'], 'gone']],
            'get_frost_forecast' => [[['days' => 3], 'hers']],
            'get_plant' => [[['id' => $rose], 'gone'], [['id' => $draft], 'gone']],
            'list_collection' => [[[], 'hers']],
            'list_spaces' => [[[], 'hers']],
            'offer_choices' => [[['question' => 'Which one?', 'options' => ['This one', 'That one']], 'hers']],
            'propose_plant' => [[PlantToolsTest::proposal(), 'hers']],
            'propose_planting' => [[['plant_id' => $rose, 'name' => 'Mine now'], 'gone'], [['plant_id' => $this->gardens->lavender->getId()->toRfc4122(), 'name' => 'Mine now', 'space_id' => $this->gardens->terrace->getId()->toRfc4122()], 'gone']],
            // Their space of this name is not hers: hers may have one
            'propose_space' => [[['name' => 'Terrace of the allotment'], 'hers']],
            'search_plant' => [[['query' => 'hortensia'], 'hers']],
            'upcoming_reminders' => [[['days' => 366], 'hers']],
        ];
    }

    /**
     * The same error as for a UUID that does not exist, or a malformed id: theirs is not said to exist.
     *
     * @param array<string, mixed> $arguments
     */
    private function assertGone(Client $client, string $tool, array $arguments): void
    {
        $error = $this->callToolExpectingError($tool, $arguments, $client);
        // Theirs only: hers stays
        $theirs = array_map(static fn ($resource): string => $resource->getId()->toRfc4122(), [$this->gardens->rose, $this->gardens->draft, $this->gardens->terrace]);
        $ids = array_filter($arguments, static fn (mixed $value): bool => \in_array($value, $theirs, true));
        $ghostError = $this->callToolExpectingError($tool, array_map(static fn (mixed $value): mixed => \in_array($value, $theirs, true) ? self::GHOST : $value, $arguments), $client);

        self::assertSame($ghostError, str_replace($ids, self::GHOST, $error), \sprintf('%s(%s)', $tool, json_encode($arguments)));
        self::assertNoNameOfTheirs($error, $tool);

        try {
            $client->callTool($tool, array_map(static fn (mixed $value): mixed => \in_array($value, $theirs, true) ? self::MALFORMED : $value, $arguments));
            self::fail(\sprintf('%s takes a malformed id.', $tool));
        } catch (RequestException $e) {
            self::assertStringContainsString('Value does not match the required format: `uuid`', $e->getMessage(), $tool);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function assertHers(Client $client, string $tool, array $arguments): void
    {
        $result = $this->callTool($tool, $arguments, $client);
        $json = (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // A card of a new space says the name it was given, nothing of the space of this name in their garden
        if ('propose_space' !== $tool) {
            self::assertNoNameOfTheirs($json, $tool);
        }

        match ($tool) {
            'list_collection' => self::assertSame(['Lavender of Carol'], array_column($result['plants'], 'common_name')),
            'list_spaces' => self::assertSame(['Window box of Carol'], array_column($result['spaces'], 'name')),
            // At Lille, not at Lyon: rounded to a tenth of a degree
            'get_frost_forecast' => self::assertSame([50.6, 3.1], [$result['latitude'], $result['longitude']]),
            'propose_plant' => self::assertTrue($this->gardens->balcony->getId()->equals(self::getContainer()->get(PlantRepository::class)->find($result['plant_id'])?->getGarden()->getId()), 'A draft of her garden.'),
            'propose_space' => self::assertSame(['name' => 'Terrace of the allotment', 'exposure' => null, 'soil' => null], array_intersect_key($result, ['name' => true, 'exposure' => true, 'soil' => true]), 'A new space of hers: not "exists already", nor the conditions of theirs.'),
            default => null,
        };
    }

    private function readError(string $uri): string
    {
        try {
            $this->external()->readResource($uri);
            self::fail($uri . ' should not be readable.');
        } catch (RequestException $e) {
            return $e->getMessage();
        }
    }

    private static function assertNoNameOfTheirs(string $text, string $what): void
    {
        foreach (TwoGardens::NAMES as $name) {
            self::assertStringNotContainsString($name, $text, \sprintf('%s says "%s".', $what, $name));
        }
    }

    private function assertTheirGardenIsUntouched(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $plants = self::getContainer()->get(PlantRepository::class);

        self::assertSame([ReminderType::Pruning, ReminderType::Frost], $plants->get($this->gardens->allotment, $this->gardens->rose->getId()->toRfc4122())->getEnabledReminderTypes());
        self::assertSame(PlantStatus::Draft, $plants->get($this->gardens->allotment, $this->gardens->draft->getId()->toRfc4122())->getStatus(), 'Not validated by her external client.');
    }

    /**
     * The agent of her chat, with the token of a run in her garden.
     */
    private function agent(): Client
    {
        return $this->mcp('/_mcp/agent', $this->gardens->carol, $this->gardens->balcony);
    }

    /**
     * An external client of hers: what stands for OAuth until phase 3.
     */
    private function external(): Client
    {
        return $this->mcp('/_mcp', $this->gardens->carol, $this->gardens->balcony);
    }
}
