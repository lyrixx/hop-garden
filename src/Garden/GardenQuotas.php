<?php

namespace App\Garden;

use App\Garden\Entity\Garden;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * What a garden may ask the LLM in a day, whoever of its members asks (config/packages/framework.yaml, rate_limiter):
 * the runs of its agent, and the Latin names of the species search. And the invitations it sends by email in a day.
 * Spent, the pages say so, translated.
 */
final readonly class GardenQuotas
{
    public function __construct(
        #[Target('garden_agent_runs')]
        private RateLimiterFactoryInterface $agentRuns,
        #[Target('garden_latin_names')]
        private RateLimiterFactoryInterface $latinNames,
        #[Target('garden_invitations')]
        private RateLimiterFactoryInterface $invitations,
    ) {
    }

    /**
     * @return bool false when the runs of the day are spent: nothing is sent to the agent
     */
    public function consumeAgentRun(Garden $garden): bool
    {
        return $this->agentRuns->create(self::key($garden))->consume()->isAccepted();
    }

    /**
     * @return bool false when the questions of the day are spent: the search goes without
     */
    public function consumeLatinNames(Garden $garden): bool
    {
        return $this->latinNames->create(self::key($garden))->consume()->isAccepted();
    }

    /**
     * @return bool false when the emails of the day are spent: no invitation sent, nor sent again
     */
    public function consumeInvitation(Garden $garden): bool
    {
        return $this->invitations->create(self::key($garden))->consume()->isAccepted();
    }

    private static function key(Garden $garden): string
    {
        return 'garden-' . $garden->getId();
    }
}
