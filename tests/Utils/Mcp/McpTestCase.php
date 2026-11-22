<?php

namespace App\Tests\Utils\Mcp;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use App\Mcp\Agent\AgentRunTokens;
use App\Story\DefaultGardenStory;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\UuidV7;

/**
 * The MCP servers over HTTP, in process, as a member in one of their gardens: by default the owner of the garden of the
 * test, where the factories put what they make. /_mcp/agent with the token of a run, as the worker; /_mcp with what
 * stands for an OAuth access token (McpClientAuthenticator).
 */
abstract class McpTestCase extends KernelTestCase
{
    use ClockSensitiveTrait;

    /** @var array<string, Client> */
    private array $clients = [];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->disconnect();
        }
        $this->clients = [];

        parent::tearDown();
    }

    protected function mcp(string $path = '/_mcp', ?User $member = null, ?Garden $garden = null): Client
    {
        $garden ??= self::gardenOfTheTest();
        $member ??= $garden->getOwner();
        $key = \sprintf('%s %d %d', $path, $member->getId(), $garden->getId());
        if (isset($this->clients[$key])) {
            return $this->clients[$key];
        }

        $authorization = '/_mcp/agent' === $path
            ? 'Bearer ' . self::getContainer()->get(AgentRunTokens::class)->issue($member, $garden, new UuidV7())
            : McpClientAuthenticator::header($member->getId(), $garden->getId());

        $client = Client::builder()->setClientInfo('hop-garden-tests', '1.0.0')->setMaxRetries(0)->build();
        $client->connect(new HttpTransport('http://localhost' . $path, ['Authorization' => $authorization], httpClient: self::getContainer()->get(KernelHttpClient::class)));

        return $this->clients[$key] = $client;
    }

    protected static function gardenOfTheTest(): Garden
    {
        $garden = DefaultGardenStory::get('garden');
        self::assertInstanceOf(Garden::class, $garden);

        return $garden;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    protected function callTool(string $name, array $arguments = [], ?Client $client = null): array
    {
        $result = ($client ?? $this->mcp())->callTool($name, $arguments);

        self::assertFalse($result->isError, \sprintf('Tool "%s" failed: %s', $name, $this->textOf($result)));
        self::assertIsArray($result->structuredContent);

        /* @var array<string, mixed> $structuredContent */
        return $result->structuredContent;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function callToolExpectingError(string $name, array $arguments = [], ?Client $client = null): string
    {
        $result = ($client ?? $this->mcp())->callTool($name, $arguments);

        self::assertTrue($result->isError, \sprintf('Tool "%s" should have failed.', $name));

        return $this->textOf($result);
    }

    protected function textOf(CallToolResult $result): string
    {
        $text = '';
        foreach ($result->content as $content) {
            if ($content instanceof TextContent && \is_string($content->text)) {
                $text .= $content->text;
            }
        }

        return $text;
    }
}
