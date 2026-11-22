<?php

namespace App\Garden;

use App\Garden\Entity\Garden;
use App\Plant\PlantRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * The slug of a new garden, species, plant or space, the name of its URL: from its name, in the language of the request,
 * free in its garden (in the whole site for a garden), suffixed when taken ("rose-2"). Fixed at creation: a renaming
 * keeps the URL. The UUIDs stay inside.
 *
 * Two creations at once would pick the same one, and the second would fail on the unique key: the slugs of the garden
 * are locked until the transaction that inserts the row commits (an advisory lock of PostgreSQL, whatever the server
 * of the request), and the other creation waits, then picks the next suffix. The caller takes the slug and flushes in
 * the same transaction (EntityManager::wrapInTransaction()).
 */
final readonly class Slugs
{
    /**
     * The pages under a resource of the garden: "/g/{garden}/spaces/new" is no space named "New".
     */
    public const array RESERVED = ['new'];

    /**
     * The transliteration of a long name in another script may outgrow it.
     */
    private const int MAX_LENGTH = 100;

    public function __construct(
        private SluggerInterface $slugger,
        private Connection $connection,
        private GardenRepository $gardenRepository,
        private PlantRepository $plantRepository,
        private PlantingRepository $plantingRepository,
        private SpaceRepository $spaceRepository,
    ) {
    }

    public function forGarden(string $name): string
    {
        $base = $this->base($name, 'garden');
        $this->lock('garden');

        return self::firstFree($base, $this->gardenRepository->findSlugsStartingWith($base));
    }

    /**
     * From its Latin name: "hydrangea-paniculata".
     */
    public function forSpecies(Garden $garden, string $scientificName): string
    {
        $base = $this->base($scientificName, 'species');
        $this->lock('species', $garden);

        return self::firstFree($base, $this->plantRepository->findSlugsStartingWith($garden, $base));
    }

    public function forPlanting(Garden $garden, string $name): string
    {
        $base = $this->base($name, 'plant');
        $this->lock('planting', $garden);

        return self::firstFree($base, $this->plantingRepository->findSlugsStartingWith($garden, $base));
    }

    public function forSpace(Garden $garden, string $name): string
    {
        $base = $this->base($name, 'space');
        $this->lock('space', $garden);

        return self::firstFree($base, $this->spaceRepository->findSlugsStartingWith($garden, $base));
    }

    /**
     * The base itself, else the first suffix free, from 2. A reserved one counts as taken.
     *
     * @param list<string> $taken
     */
    public static function firstFree(string $base, array $taken): string
    {
        $taken = array_flip([...$taken, ...self::RESERVED]);
        $slug = $base;
        for ($suffix = 2; isset($taken[$slug]); ++$suffix) {
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }

    /**
     * Lower case, in the language of the request; the fallback for a name without a letter to keep ("🌹").
     */
    private function base(string $name, string $fallback): string
    {
        $slug = rtrim(substr($this->slugger->slug($name)->lower()->toString(), 0, self::MAX_LENGTH), '-');

        return '' !== $slug ? $slug : $fallback;
    }

    /**
     * Until the transaction commits: the other creations in the same scope wait.
     */
    private function lock(string $kind, ?Garden $garden = null): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A slug is picked within the transaction that inserts its row: wrap both in EntityManager::wrapInTransaction().');
        }

        $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['slug.' . $kind . '.' . ($garden?->getId()->toRfc4122() ?? 'site')]);
    }
}
