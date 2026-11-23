<?php

namespace App\Tests\Functional\Mcp;

use App\Account\Entity\User;
use App\Factory\GardenFactory;
use App\Factory\PlantFactory;
use App\Factory\UserFactory;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Planting;
use App\Garden\PlantingRepository;
use App\Mcp\Agent\AgentRunTokens;
use App\Mcp\Security\McpEntryPoint;
use App\Plant\PlantRepository;
use App\Tests\Integration\Mcp\PlantToolsTest;
use App\Tests\Utils\Garden\GardenPathsTrait;
use App\Tests\Utils\Mcp\McpClientAuthenticator;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\UuidV7;

/**
 * JSON-RPC over HTTP, as an MCP client sends it: /_mcp with what stands for an OAuth access token until phase 3
 * (McpClientAuthenticator), /_mcp/agent with the token of a run. Without one, a 401.
 */
final class McpEndpointTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use GardenPathsTrait;
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
        self::assertTrue(self::getContainer()->get(PlantRepository::class)->get($this->gardenOfTheTest(), $plantId)->isValidated());
        self::assertSame([], self::getContainer()->get(PlantingRepository::class)->findBy(['plant' => $plantId]), 'Validated only.');

        // Then the plant itself: the user adds it on the page of its card
        $proposed = $this->rpc('/_mcp', 'tools/call', ['name' => 'propose_planting', 'arguments' => ['plant_id' => $plantId, 'name' => 'Hortensia']], $session);
        $url = $proposed['result']['structuredContent']['validation_url'];
        self::assertStringStartsWith('https://hop-garden.test/g/the-garden-of-the-tests/proposals/plantings/', $url, 'In the garden of its token.');
        self::assertSame([], self::getContainer()->get(PlantingRepository::class)->findBy(['plant' => $plantId]), 'Nothing until the user taps the card.');

        // Opened in Hop Garden, by a member
        $this->logIn($this->client);
        $crawler = $this->client->request('GET', substr($url, \strlen('https://hop-garden.test')));
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
        self::assertFalse(self::getContainer()->get(PlantRepository::class)->get($this->gardenOfTheTest(), $plant->getId())->isValidated());
    }

    #[TestWith(['/_mcp'])]
    #[TestWith(['/_mcp/agent'])]
    public function testAnUnknownHostIsRefused(string $path): void
    {
        $this->client->request('POST', 'http://evil.example' . $path, server: $this->headers($path), content: self::json(self::initialize()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Forbidden: Invalid Host header.', $this->client->getInternalResponse()->getContent());
    }

    /**
     * Until OAuth (phase 3): a clean 401, a Bearer challenge, never the login page. The session of a member logged in
     * on the website does not count: the firewall is stateless.
     */
    public function testTheServerOfTheExternalClientsRefusesWhoeverHasNoToken(): void
    {
        $this->logIn($this->client);
        $this->client->request('GET', self::inGarden('/garden'));
        self::assertResponseIsSuccessful();

        $this->client->request('POST', 'http://localhost/_mcp', server: self::withoutToken(), content: self::json(self::initialize()));

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer realm="Hop Garden"');
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertStringContainsString('OAuth comes next', $this->client->getInternalResponse()->getContent());
    }

    /**
     * @param ?\Closure(self): string $token of the Authorization header, none when null
     */
    #[DataProvider('provideTheServerOfTheAgentRefusesAnyOtherTokenThanTheOneOfARunCases')]
    public function testTheServerOfTheAgentRefusesAnyOtherTokenThanTheOneOfARun(?\Closure $token, string $error): void
    {
        $headers = self::withoutToken();
        if (null !== $token) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token($this);
        }

        $this->client->request('POST', 'http://localhost/_mcp/agent', server: $headers, content: self::json(self::initialize()));

        self::assertResponseStatusCodeSame(401);
        self::assertStringStartsWith('Bearer realm="Hop Garden"', (string) $this->client->getInternalResponse()->getHeader('WWW-Authenticate'));
        self::assertStringContainsString($error, $this->client->getInternalResponse()->getContent());
    }

    /**
     * @return iterable<string, array{?\Closure(self): string, string}>
     */
    public static function provideTheServerOfTheAgentRefusesAnyOtherTokenThanTheOneOfARunCases(): iterable
    {
        yield 'no token' => [null, 'Only the chat agent of Hop Garden calls this MCP server'];
        yield 'an expired token' => [static function (self $test): string {
            $token = $test->runToken();
            self::mockTime('+16 minutes');

            return $token;
        }, 'The token of the agent run expired'];
        yield 'a forged signature' => [static fn (self $test): string => explode('.', $test->runToken())[0] . '.' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), 'not signed by Hop Garden'];
        yield 'another garden in a signed payload' => [static function (self $test): string {
            [$payload, $signature] = explode('.', $test->runToken());
            $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($claims);
            $claims['garden'] = GardenFactory::createOne()->getId();

            return rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=') . '.' . $signature;
        }, 'not signed by Hop Garden'];
        yield 'not a token at all' => [static fn (): string => 'hello', 'malformed'];
        yield 'an OAuth token of /_mcp' => [static fn (self $test): string => substr(McpClientAuthenticator::header($test->gardenOfTheTest()->getOwner()->getId(), $test->gardenOfTheTest()->getId()), \strlen('Bearer ')), 'malformed'];
        yield 'a member removed from the garden since' => [static function (self $test): string {
            $bob = UserFactory::new()->approved()->create();
            $garden = GardenFactory::new()->withMembers($bob)->create();
            $token = $test->runToken($bob, $garden);
            $garden->leave($bob);
            self::getContainer()->get(EntityManagerInterface::class)->flush();

            return $token;
        }, 'no member of its garden any more'];
    }

    public function testTheTokenOfARunBindsTheToolsToItsGarden(): void
    {
        $carol = UserFactory::new()->approved()->create();
        $balcony = GardenFactory::new()->ownedBy($carol)->create();
        PlantFactory::new()->validated()->create(['commonName' => 'Rose of the garden of the test']);
        PlantFactory::new()->validated()->create(['garden' => $balcony, 'commonName' => 'Lavender of the balcony']);

        $this->rpc('/_mcp/agent', 'initialize', self::initialize()['params'], token: $this->runToken($carol, $balcony));
        $session = $this->client->getInternalResponse()->getHeader('mcp-session-id') ?? self::fail('A session is opened.');
        $listed = $this->rpc('/_mcp/agent', 'tools/call', ['name' => 'list_collection', 'arguments' => new \stdClass()], $session, $this->runToken($carol, $balcony));

        self::assertSame(['Lavender of the balcony'], array_column($listed['result']['structuredContent']['plants'], 'common_name'));
    }

    /**
     * Of the owner of the garden of the test, unless told.
     */
    public function runToken(?User $member = null, ?Garden $garden = null): string
    {
        $garden ??= $this->gardenOfTheTest();

        return self::getContainer()->get(AgentRunTokens::class)->issue($member ?? $garden->getOwner(), $garden, new UuidV7());
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(string $path, string $method, array $params, ?string $session = null, ?string $token = null): array
    {
        $headers = $this->headers($path, $session);
        if (null !== $token) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request('POST', 'http://localhost' . $path, server: $headers, content: self::json(['jsonrpc' => '2.0', 'id' => ++$this->requestId, 'method' => $method, 'params' => $params]));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        /** @var array<string, mixed> $data */
        $data = json_decode($this->client->getInternalResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    private function notify(string $path, string $method, string $session): void
    {
        $this->client->request('POST', 'http://localhost' . $path, server: $this->headers($path, $session), content: self::json(['jsonrpc' => '2.0', 'method' => $method]));
    }

    /**
     * With the token of the server, for the owner of the garden of the test: what stands for OAuth on /_mcp, a run on
     * /_mcp/agent.
     *
     * @return array<string, string>
     */
    private function headers(string $path, ?string $session = null): array
    {
        $garden = $this->gardenOfTheTest();

        return ['HTTP_AUTHORIZATION' => McpEntryPoint::AGENT_PATH === $path ? 'Bearer ' . $this->runToken() : McpClientAuthenticator::header($garden->getOwner()->getId(), $garden->getId())] + self::withoutToken($session);
    }

    /**
     * @return array<string, string>
     */
    private static function withoutToken(?string $session = null): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
        if (null !== $session) {
            $headers['HTTP_MCP_SESSION_ID'] = $session;
            $headers['HTTP_MCP_PROTOCOL_VERSION'] = self::PROTOCOL_VERSION;
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private static function initialize(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => self::PROTOCOL_VERSION, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'tests', 'version' => '1.0.0']]];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
