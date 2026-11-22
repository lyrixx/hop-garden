<?php

namespace App\Tests\Integration\Mcp;

use App\Plant\PlantRepository;
use App\Tests\Utils\Factory\PlantFactory;
use App\Tests\Utils\Mcp\McpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\RequestException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Tool;

final class ServerCapabilitiesTest extends McpTestCase
{
    private const array SHARED_TOOLS = [
        'disable_reminder',
        'enable_reminder',
        'get_frost_forecast',
        'get_plant',
        'list_collection',
        'list_spaces',
        'propose_plant',
        'propose_planting',
        'propose_space',
        'search_plant',
        'upcoming_reminders',
    ];

    public function testTheExternalServerCanConfirmPlantsButOffersNoChoices(): void
    {
        $tools = $this->toolNames($this->mcp('/_mcp')->listTools()->tools);

        self::assertSame([...self::SHARED_TOOLS, 'confirm_plant'], [...array_values(array_diff($tools, ['confirm_plant'])), 'confirm_plant']);
        self::assertContains('confirm_plant', $tools);
        self::assertNotContains('offer_choices', $tools, 'Its buttons show in the chat only.');
    }

    public function testTheAgentServerCannotConfirmPlantsButOffersChoices(): void
    {
        $tools = $this->toolNames($this->mcp('/_mcp/agent')->listTools()->tools);

        self::assertSame(self::SHARED_TOOLS, array_values(array_diff($tools, ['offer_choices'])));
        self::assertContains('offer_choices', $tools);
        self::assertNotContains('confirm_plant', $tools);
    }

    public function testEveryArgumentOfTheNewToolsIsDescribed(): void
    {
        foreach ($this->mcp('/_mcp/agent')->listTools()->tools as $tool) {
            if (!\in_array($tool->name, ['list_spaces', 'offer_choices', 'propose_planting', 'propose_space'], true)) {
                continue;
            }
            /** @var array<string, array<string, mixed>> $properties */
            $properties = $tool->inputSchema['properties'] ?? [];
            foreach ($properties as $name => $property) {
                self::assertMatchesRegularExpression('/^[a-z_]+$/', $name, 'snake_case');
                self::assertNotEmpty($property['description'] ?? null, \sprintf('%s(%s) is described.', $tool->name, $name));
            }
        }
    }

    public function testResources(): void
    {
        $plant = PlantFactory::validated('Lavender');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($plant);
        $entityManager->flush();

        $resources = $this->mcp()->listResources()->resources;
        self::assertSame(['hopgarden://collection'], array_map(static fn ($resource) => $resource->uri, $resources));

        $templates = $this->mcp()->listResourceTemplates()->resourceTemplates;
        self::assertSame(['hopgarden://plants/{id}'], array_map(static fn ($template) => $template->uriTemplate, $templates));

        $collection = $this->mcp()->readResource('hopgarden://collection')->contents[0];
        self::assertInstanceOf(TextResourceContents::class, $collection);
        self::assertStringContainsString('**Lavender**', $collection->text);

        $sheet = $this->mcp()->readResource('hopgarden://plants/' . $plant->getId())->contents[0];
        self::assertInstanceOf(TextResourceContents::class, $sheet);
        self::assertStringContainsString('| Hardiness | -15 °C | low |', $sheet->text);
        self::assertStringContainsString('| Pruning | March | high |', $sheet->text);
    }

    public function testAddPlantPrompt(): void
    {
        $prompt = $this->mcp()->getPrompt('add_plant', ['name' => 'hortensia']);

        $content = $prompt->messages[0]->content;
        self::assertInstanceOf(TextContent::class, $content);
        self::assertIsString($content->text);
        self::assertStringContainsString('Call search_plant with "hortensia"', $content->text);
        self::assertStringContainsString('Call confirm_plant only once I explicitly confirmed it', $content->text);
    }

    public function testTheAgentServerRefusesConfirmPlant(): void
    {
        $plant = PlantFactory::draft();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($plant);
        $entityManager->flush();

        try {
            $this->mcp('/_mcp/agent')->callTool('confirm_plant', ['id' => $plant->getId()]);
            self::fail('confirm_plant must not exist on the server of the agent.');
        } catch (RequestException $e) {
            self::assertStringContainsString('confirm_plant', $e->getMessage());
        }

        $entityManager->clear();
        self::assertFalse(self::getContainer()->get(PlantRepository::class)->get($plant->getId())->isValidated(), 'Only the user validates.');
    }

    public function testTheExternalServerIntroducesItself(): void
    {
        self::assertSame('hop-garden', $this->mcp('/_mcp')->getServerInfo()?->name);
        self::assertStringContainsString('Never validate on your own.', (string) $this->mcp()->getInstructions());
    }

    public function testTheServerOfTheAgentHasToolsOnly(): void
    {
        $this->mcp('/_mcp/agent');

        self::assertSame('hop-garden-agent', $this->mcp()->getServerInfo()?->name);
        self::assertSame([], $this->mcp()->listPrompts()->prompts);
        self::assertSame([], $this->mcp()->listResources()->resources);
    }

    public function testADraftCareSheetIsReadableToo(): void
    {
        $plant = PlantFactory::draft();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($plant);
        $entityManager->flush();

        $sheet = $this->mcp()->readResource('hopgarden://plants/' . $plant->getId())->contents[0];

        self::assertInstanceOf(TextResourceContents::class, $sheet);
        self::assertStringContainsString('Bigleaf hydrangea', $sheet->text);
    }

    public function testAnUnknownPlantHasNoCareSheet(): void
    {
        foreach (['999999', 'abc'] as $id) {
            try {
                $this->mcp()->readResource('hopgarden://plants/' . $id);
                self::fail(\sprintf('No care sheet for "%s".', $id));
            } catch (RequestException $e) {
                self::assertStringContainsString(\sprintf('No plant with id "%s".', $id), $e->getMessage());
            }
        }
    }

    public function testAnEmptyCollectionResource(): void
    {
        $collection = $this->mcp()->readResource('hopgarden://collection')->contents[0];

        self::assertInstanceOf(TextResourceContents::class, $collection);
        self::assertStringContainsString('No validated plant in the garden yet.', $collection->text);
    }

    /**
     * @param list<Tool> $tools
     *
     * @return list<string>
     */
    private function toolNames(array $tools): array
    {
        $names = array_map(static fn (Tool $tool): string => $tool->name, $tools);
        sort($names);

        return $names;
    }
}
