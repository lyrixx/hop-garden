<?php

namespace App\Mcp\Agent;

/**
 * The token of the agent run in progress in the worker: AgentRunHttpClient sends it with the requests of the MCP
 * client, from the first (initialize, tools/list) to the last tool call. None between two runs: run() puts the previous
 * one back, whatever happens.
 */
final class AgentRunContext
{
    private ?string $token = null;

    /**
     * @template T
     *
     * @param callable(): T $run
     *
     * @return T
     */
    public function run(string $token, callable $run): mixed
    {
        $previous = $this->token;
        $this->token = $token;

        try {
            return $run();
        } finally {
            $this->token = $previous;
        }
    }

    public function getToken(): ?string
    {
        return $this->token;
    }
}
