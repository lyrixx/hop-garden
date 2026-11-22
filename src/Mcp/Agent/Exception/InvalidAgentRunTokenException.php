<?php

namespace App\Mcp\Agent\Exception;

final class InvalidAgentRunTokenException extends \RuntimeException
{
    public static function malformed(): self
    {
        return new self('The token of the agent run is malformed.');
    }

    public static function forged(): self
    {
        return new self('The token of the agent run was not signed by Hop Garden.');
    }

    public static function expired(\DateTimeImmutable $expiresAt): self
    {
        return new self(\sprintf('The token of the agent run expired at %s.', $expiresAt->format(DATE_ATOM)));
    }
}
