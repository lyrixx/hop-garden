<?php

namespace App\DataSource;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Caches the answers of the external APIs. The demo mode gets its own keys, so switching
 * APP_DEMO never serves a recorded answer as a real one.
 */
final readonly class DataSourceCache
{
    public function __construct(
        #[Target('data_source.cache')]
        private CacheInterface $cache,
        #[Autowire(env: 'bool:APP_DEMO')]
        private bool $demo,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $compute
     *
     * @return T
     */
    public function get(string $key, int $ttlInSeconds, callable $compute): mixed
    {
        $key = ($this->demo ? 'demo.' : 'live.') . hash('xxh128', $key);

        return $this->cache->get($key, static function (ItemInterface $item) use ($ttlInSeconds, $compute): mixed {
            $item->expiresAfter($ttlInSeconds);

            return $compute();
        });
    }
}
