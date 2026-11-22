<?php

namespace App\Tests\Functional\Mcp;

use App\Factory\PlantFactory;
use App\Garden\Entity\Planting;
use App\Garden\PlantingRepository;
use App\Plant\PlantRepository;
use App\Tests\Integration\Mcp\PlantToolsTest;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * JSON-RPC over HTTP, as an MCP client sends it.
 */
final class McpEndpointTest extends WebTestCase
{
    use LogInTrait;

    private const string PROTOCOL_VERSION = '2025-06-18';

    private KernelBrowser $client;
    private int $requestId = 0;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAnExternalClientProposesThenConfirmsAPlant(): void
    {
        $initialize = $this->rpc('/_mcp', 'initialize', ['protocolVersion' => self::PROTOCOL_VERSION, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'tests', 'version' => '1.0.0']]);
        $session = $this->client->getInternalResponse()->getHeader('mcp-session-id') ?? self::fail('A session is opened.');
        self::assertSame('hop-garden', $initialize['result']['serverInfo']['name']);
        self::assertArrayHasKey('resources', $initialize['result']['capabilities']);
        self::assertArrayHasKey('prompts', $initialize['result']['capabilities']);
        self::assertStringContainsString('Never validate on your own.', $initialize['result']['instructions']);

        $this->notify('/_mcp', 'notifications/initialized', $session);
        self::assertResponseStatusCodeSame(202);

        $proposed = $this->rpc('/_mcp', 'tools/call', ['name' => 'propose_plant', 'arguments' => PlantToolsTest::proposal()], $session);
        $plantId = $proposed['result']['structuredContent']['plant_id'];
        self::assertSame('draft', $proposed['result']['structuredContent']['status']);

        $confirmed = $this->rpc('/_mcp', 'tools/call', ['name' => 'confirm_plant', 'arguments' => ['id' => $plantId]], $session);
        self::assertSame('validated', $confirmed['result']['structuredContent']['status']);

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertTrue(self::getContainer()->get(PlantRepository::class)->get($plantId)->isValidated());
        self::assertSame([], self::getContainer()->get(PlantingRepository::class)->findBy(['plant' => $plantId]), 'Validated only.');

        // Then the plant itself: the user adds it on the page of its card
        $proposed = $this->rpc('/_mcp', 'tools/call', ['name' => 'propose_planting', 'arguments' => ['plant_id' => $plantId, 'name' => 'Hortensia']], $session);
        $url = $proposed['result']['structuredContent']['validation_url'];
        self::assertStringStartsWith('https://hop-garden.test/proposals/plantings/', $url);
        self::assertSame([], self::getContainer()->get(PlantingRepository::class)->findBy(['plant' => $plantId]), 'Nothing until the user taps the card.');

        // Opened in Hop Garden, by a member
        $this->logIn($this->client);
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Add to my garden');
        $this->client->submit($crawler->selectButton('Add to my garden')->form());
        self::assertResponseRedirects(substr($url, \strlen('https://hop-garden.test')), 303);
        self::assertSame(['Hortensia'], array_map(static fn (Planting $planting): string => $planting->getName(), self::getContainer()->get(PlantingRepository::class)->findBy(['plant' => $plantId])));
    }

    public function testTheServerOfTheAgentCannotConfirmAPlant(): void
    {
        $plant = PlantFactory::createOne();

        $initialize = $this->rpc('/_mcp/agent', 'initialize', ['protocolVersion' => self::PROTOCOL_VERSION, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'tests', 'version' => '1.0.0']]);
        $session = $this->client->getInternalResponse()->getHeader('mcp-session-id') ?? self::fail('A session is opened.');
        self::assertSame('hop-garden-agent', $initialize['result']['serverInfo']['name']);
        self::assertArrayNotHasKey('resources', $initialize['result']['capabilities']);
        self::assertArrayNotHasKey('prompts', $initialize['result']['capabilities']);

        $confirmed = $this->rpc('/_mcp/agent', 'tools/call', ['name' => 'confirm_plant', 'arguments' => ['id' => $plant->getId()]], $session);

        self::assertSame(['code' => -32602, 'message' => 'Tool not found: "confirm_plant".'], $confirmed['error']);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertFalse(self::getContainer()->get(PlantRepository::class)->get($plant->getId())->isValidated());
    }

    #[TestWith(['/_mcp'])]
    #[TestWith(['/_mcp/agent'])]
    public function testAnUnknownHostIsRefused(string $path): void
    {
        $this->client->request('POST', 'http://evil.example' . $path, server: self::headers(), content: self::json(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => self::PROTOCOL_VERSION, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'tests', 'version' => '1.0.0']]]));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Forbidden: Invalid Host header.', $this->client->getInternalResponse()->getContent());
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(string $path, string $method, array $params, ?string $session = null): array
    {
        $this->client->request('POST', 'http://localhost' . $path, server: self::headers($session), content: self::json(['jsonrpc' => '2.0', 'id' => ++$this->requestId, 'method' => $method, 'params' => $params]));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        /** @var array<string, mixed> $data */
        $data = json_decode($this->client->getInternalResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    private function notify(string $path, string $method, string $session): void
    {
        $this->client->request('POST', 'http://localhost' . $path, server: self::headers($session), content: self::json(['jsonrpc' => '2.0', 'method' => $method]));
    }

    /**
     * @return array<string, string>
     */
    private static function headers(?string $session = null): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
        if (null !== $session) {
            $headers['HTTP_MCP_SESSION_ID'] = $session;
            $headers['HTTP_MCP_PROTOCOL_VERSION'] = self::PROTOCOL_VERSION;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
