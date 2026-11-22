<?php

namespace App\Mcp\Agent\Security;

use App\Mcp\Agent\Model\AgentRunClaims;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\BadgeInterface;

/**
 * The claims of the run, from the passport to the security token: AgentRunAuthenticator::createToken().
 */
final readonly class AgentRunBadge implements BadgeInterface
{
    public function __construct(
        public AgentRunClaims $claims,
    ) {
    }

    public function isResolved(): bool
    {
        return true;
    }
}
