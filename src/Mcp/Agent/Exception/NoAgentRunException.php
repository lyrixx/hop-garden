<?php

namespace App\Mcp\Agent\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * A request of the MCP client of the chat outside a run (the disconnection of a reset container): it would be refused
 * anyway, it is not sent. The MCP SDK takes it as any failure of its HTTP client.
 */
final class NoAgentRunException extends \RuntimeException implements ClientExceptionInterface
{
    public static function create(): self
    {
        return new self('No agent run in progress: the MCP server of the agent answers the token of a run only.');
    }
}
