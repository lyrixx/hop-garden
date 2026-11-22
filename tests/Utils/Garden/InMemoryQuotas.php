<?php

namespace App\Tests\Utils\Garden;

use App\Garden\GardenQuotas;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The quotas of the gardens of a unit test, kept in memory, as low as the test wants them.
 */
final class InMemoryQuotas
{
    public static function create(int $agentRuns = 200, int $latinNames = 100): GardenQuotas
    {
        return new GardenQuotas(self::limiter('garden_agent_runs', $agentRuns), self::limiter('garden_latin_names', $latinNames));
    }

    private static function limiter(string $id, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 day'], new InMemoryStorage());
    }
}
