<?php

namespace App\Chat;

/**
 * The MCP toolbox prefixes the tools of a remote server with its name (see config/packages/ai.yaml).
 */
final class ToolName
{
    public const string PREFIX = 'hopgarden_';

    public static function toMcp(string $agentToolName): string
    {
        return str_starts_with($agentToolName, self::PREFIX) ? substr($agentToolName, \strlen(self::PREFIX)) : $agentToolName;
    }
}
