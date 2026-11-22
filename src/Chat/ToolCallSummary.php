<?php

namespace App\Chat;

/**
 * One line per tool call in the chat, to show that the agent goes through MCP.
 */
final class ToolCallSummary
{
    private const int MAX_VALUE_LENGTH = 40;

    /**
     * @param array<string, mixed> $arguments
     */
    public static function of(string $agentToolName, array $arguments): string
    {
        $parts = [];
        foreach ($arguments as $name => $value) {
            $parts[] = $name . ': ' . self::summarize($value);
        }

        return ToolName::toMcp($agentToolName) . '(' . implode(', ', $parts) . ')';
    }

    private static function summarize(mixed $value): string
    {
        if (\is_array($value)) {
            return array_is_list($value) ? '[' . \count($value) . ' items]' : '{…}';
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '?';

        return mb_strlen($encoded) > self::MAX_VALUE_LENGTH ? mb_substr($encoded, 0, self::MAX_VALUE_LENGTH) . '…' : $encoded;
    }
}
