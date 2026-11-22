<?php

namespace App\Tests\Http;

/**
 * The transport of every HTTP client of the test environment (framework.http_client.mock_response_factory): the
 * default one, the scoped ones and those the AI platforms and the MCP client are built on. A request that nothing
 * simulates throws, and stays in the ledger: UnexpectedRequestExtension fails the run even when the code under test
 * swallows the exception (the MCP server, the HTTP kernel...).
 */
final class UnexpectedRequestGuard
{
    /** @var list<string> */
    private static array $requests = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): never
    {
        $request = $method . ' ' . $url;
        self::$requests[] = $request;

        throw new UnexpectedRequestException(\sprintf('Unexpected HTTP request in the test environment: %s. Simulate it with a MockHttpClient, or with the recorded responses of the demo mode.', $request));
    }

    /**
     * @return list<string> the requests made since the last call, as "METHOD URL"
     */
    public static function takeRequests(): array
    {
        $requests = self::$requests;
        self::$requests = [];

        return $requests;
    }
}
