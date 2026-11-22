<?php

namespace App\Chat;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The MCP tool calls show in the chat in demo mode only, where they show that the agent goes through MCP. The live
 * stream and the history both ask here.
 */
final readonly class ToolCallDisplay
{
    public function __construct(
        #[Autowire(env: 'bool:APP_DEMO')]
        private bool $demo,
    ) {
    }

    public function isShown(): bool
    {
        return $this->demo;
    }
}
