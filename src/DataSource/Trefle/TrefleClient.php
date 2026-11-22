<?php

namespace App\DataSource\Trefle;

use App\DataSource\DataSourceCache;
use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\Exception\UnexpectedResponseException;
use App\DataSource\Trefle\Model\PlantCandidate;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @see https://docs.trefle.io/reference
 */
final readonly class TrefleClient
{
    private const int MAX_CANDIDATES = 10;
    private const int CACHE_TTL_IN_SECONDS = 7 * 24 * 3600;

    public function __construct(
        #[Target('trefle.client')]
        private HttpClientInterface $httpClient,
        private DataSourceCache $cache,
        private LoggerInterface $logger,
        #[Autowire(env: 'TREFLE_TOKEN')]
        private string $token,
        #[Autowire(env: 'bool:APP_DEMO')]
        private bool $demo,
    ) {
    }

    /**
     * @return list<PlantCandidate>
     */
    public function search(string $query): array
    {
        if ('' === $this->token && !$this->demo) {
            throw DataSourceUnavailableException::notConfigured('Trefle', 'TREFLE_TOKEN');
        }

        $query = trim($query);

        return $this->cache->get('trefle.search.' . mb_strtolower($query), self::CACHE_TTL_IN_SECONDS, function () use ($query): array {
            try {
                return self::candidates($this->httpClient->request('GET', '/api/v1/plants/search', [
                    'query' => ['q' => $query, 'token' => $this->token],
                ])->toArray());
            } catch (ExceptionInterface|UnexpectedResponseException $e) {
                $this->logger->warning('Trefle search failed.', ['exception' => $e, 'query' => $query]);

                throw DataSourceUnavailableException::failed('Trefle', $e);
            }
        });
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<PlantCandidate>
     */
    private static function candidates(array $data): array
    {
        $plants = $data['data'] ?? [];
        if (!\is_array($plants) || !array_is_list($plants)) {
            throw new UnexpectedResponseException('The plants found by Trefle are not a list.');
        }

        return array_map(self::candidate(...), \array_slice($plants, 0, self::MAX_CANDIDATES));
    }

    private static function candidate(mixed $plant): PlantCandidate
    {
        if (!\is_array($plant) || !\is_int($plant['id'] ?? null) || !\is_string($plant['scientific_name'] ?? null)) {
            throw new UnexpectedResponseException('A plant found by Trefle has no id or no scientific name.');
        }

        return new PlantCandidate(
            $plant['id'],
            $plant['scientific_name'],
            self::optionalString($plant, 'common_name'),
            self::optionalString($plant, 'family'),
            self::optionalString($plant, 'image_url'),
        );
    }

    /**
     * @param array<mixed> $plant
     */
    private static function optionalString(array $plant, string $key): ?string
    {
        $value = $plant[$key] ?? null;
        if (null !== $value && !\is_string($value)) {
            throw new UnexpectedResponseException(\sprintf('The "%s" of a plant found by Trefle is not a string.', $key));
        }

        return $value;
    }
}
