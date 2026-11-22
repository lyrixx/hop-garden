<?php

namespace App\Garden\Command;

use App\Account\UserRepository;
use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\OpenMeteo\GeocodingClient;
use App\Garden\Entity\Garden;
use App\Garden\Entity\GardenLocation;
use App\Garden\GardenRepository;
use App\Garden\Slugs;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The data of before the gardens, once: a garden is created for a member, at the place of a city, and every row without
 * garden joins it, its notifications sent to that member, and the conversations of the chat become theirs. Before the
 * migration that makes them required (Version20261123120000), which refuses to run while a row has none: "castor
 * app:garden:adopt-orphans" runs the migrations up to it, this command, then that migration.
 */
#[AsCommand(name: 'app:garden:adopt-orphans', description: 'Gives the rows of before the gardens to a new garden of a member')]
final readonly class AdoptOrphansCommand
{
    /**
     * The tables whose rows belong to a garden, by its column.
     */
    private const array GARDEN_TABLES = ['space', 'plant', 'planting', 'notification'];

    public function __construct(
        private Connection $connection,
        private UserRepository $userRepository,
        private GardenRepository $gardenRepository,
        private GeocodingClient $geocodingClient,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private Slugs $slugs,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'The email of the approved member the garden is created for, its owner')]
        string $email = '',
        #[Option(description: 'The name of the garden')]
        string $name = 'My garden',
        #[Option(description: 'Its city, searched with the geocoding of Open-Meteo: the first place found')]
        string $city = '',
    ): int {
        if (!$this->gardensAreOptional() || 0 === $this->countOrphans()) {
            $io->success('Nothing to adopt: every row has its garden already.');

            return Command::SUCCESS;
        }

        $user = '' !== $email ? $this->userRepository->findOneByEmail($email) : null;
        if (null === $user || !\in_array('ROLE_USER', $user->getRoles(), true)) {
            $io->error(\sprintf('No approved member with the email "%s": create one first, "castor app:user:create --email=... --name=... --password=... --approved".', $email));

            return Command::INVALID;
        }

        try {
            $place = $this->geocodingClient->search($city, 'en')[0] ?? null;
        } catch (DataSourceUnavailableException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if (null === $place) {
            $io->error(\sprintf('No place found for the city "%s": pass --city=<a city>.', $city));

            return Command::INVALID;
        }

        $adopted = $this->connection->transactional(function () use ($user, $name, $place): array {
            $garden = Garden::create(trim($name), $this->slugs->forGarden(trim($name)), new GardenLocation($place->label(), $place->latitude, $place->longitude, $place->timezone), $user, $this->clock->now());
            $this->gardenRepository->add($garden);
            $this->entityManager->flush();

            $parameters = ['garden' => $garden->getId()->toRfc4122(), 'user' => $user->getId()->toRfc4122()];
            $adopted = [];
            foreach (self::GARDEN_TABLES as $table) {
                $set = 'notification' === $table ? 'garden_id = :garden, recipient_id = :user' : 'garden_id = :garden';
                $adopted[$table] = (int) $this->connection->executeStatement(\sprintf('UPDATE %s SET %s WHERE garden_id IS NULL', $table, $set), $parameters);
            }
            // The messages of the store, whose conversation was nobody's
            $adopted['chat_conversation'] = (int) $this->connection->executeStatement(
                'INSERT INTO conversation (id, user_id, garden_id, started_at) SELECT message.id::uuid, :user, :garden, TO_TIMESTAMP(message.updated_at) FROM chat_conversation message WHERE NOT EXISTS (SELECT 1 FROM conversation WHERE conversation.id = message.id::uuid)',
                $parameters,
            );

            return ['garden' => $garden, 'rows' => $adopted];
        });

        $io->table(['Table', 'Rows adopted'], array_map(static fn (string $table, int $count): array => [$table, $count], array_keys($adopted['rows']), $adopted['rows']));
        $io->success(\sprintf('The garden "%s" (%s) of %s, at %s (%s), has every row of before. The next migration makes it required: "bin/console doctrine:migrations:migrate" ("castor app:garden:adopt-orphans" runs it).', $adopted['garden']->getName(), $adopted['garden']->getId(), $user->getEmail(), $place->label(), $place->timezone));

        return Command::SUCCESS;
    }

    /**
     * The rows without garden, and the conversations nobody's.
     */
    private function countOrphans(): int
    {
        $queries = array_map(static fn (string $table): string => \sprintf('SELECT COUNT(*) FROM %s WHERE garden_id IS NULL', $table), self::GARDEN_TABLES);
        $queries[] = 'SELECT COUNT(*) FROM chat_conversation message WHERE NOT EXISTS (SELECT 1 FROM conversation WHERE conversation.id = message.id::uuid)';

        $orphans = 0;
        foreach ($queries as $query) {
            // A COUNT(*) is a number: DBAL types it mixed
            $orphans += (int) $this->connection->fetchOne($query); // @phpstan-ignore cast.int
        }

        return $orphans;
    }

    /**
     * Before the migration that makes the garden of a row required: after it, no row can lack one.
     */
    private function gardensAreOptional(): bool
    {
        return 'YES' === $this->connection->fetchOne("SELECT is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'space' AND column_name = 'garden_id'");
    }
}
