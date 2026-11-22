<?php

namespace App\Tests\Utils\Mcp;

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

abstract class McpTestCase extends KernelTestCase
{
    use ClockSensitiveTrait;

    private ?Client $client = null;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $this->client?->disconnect();
        $this->client = null;

        parent::tearDown();
    }

    protected function mcp(string $path = '/_mcp'): Client
    {
        if (null !== $this->client) {
            return $this->client;
        }

        $this->client = Client::builder()->setClientInfo('hop-garden-tests', '1.0.0')->setMaxRetries(0)->build();
        $this->client->connect(new HttpTransport('http://localhost' . $path, httpClient: new KernelHttpClient(self::$kernel ?? throw new \LogicException('The kernel is not booted.'))));

        return $this->client;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    protected function callTool(string $name, array $arguments = []): array
    {
        $result = $this->mcp()->callTool($name, $arguments);

        self::assertFalse($result->isError, \sprintf('Tool "%s" failed: %s', $name, $this->textOf($result)));
        self::assertIsArray($result->structuredContent);

        /* @var array<string, mixed> $structuredContent */
        return $result->structuredContent;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function callToolExpectingError(string $name, array $arguments = []): string
    {
        $result = $this->mcp()->callTool($name, $arguments);

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
