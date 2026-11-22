<?php

namespace App\Tests\Integration\DataFixtures;

use App\DataFixtures\DemoFixtures;
use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Garden\PlantingRepository;
use App\Garden\SpaceRepository;
use App\Plant\Entity\Plant;
use App\Plant\PlantRepository;
use App\Reminder\Model\ReminderType;
use App\Reminder\NotificationRepository;
use App\Reminder\ReminderChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * What "castor demo:reset" then "castor demo:frost" show, on the test database.
 */
final class DemoFixturesTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    public function testTwoPlantsInTheSpacesOfTheGardenThenAFrostAlert(): void
    {
        self::bootKernel();
        self::mockTime('2026-12-01 07:00');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        self::getContainer()->get(DemoFixtures::class)->load($entityManager);
        $entityManager->clear();

        $plants = self::getContainer()->get(PlantRepository::class)->findValidated();
        self::assertSame(
            ['Climbing rose' => [ReminderType::Pruning, ReminderType::Deadheading], 'Lemon tree' => [ReminderType::Frost]],
            array_merge(...array_map(static fn (Plant $plant): array => [$plant->getCommonName() => $plant->getEnabledReminderTypes()], $plants)),
        );
        $plantings = self::getContainer()->get(PlantingRepository::class)->findAllByName();
        self::assertSame(
            ['Lemon tree on the terrace' => ['Rose on the trellis'], 'Rose by the gate' => [], 'Rose on the trellis' => ['Lemon tree on the terrace']],
            array_merge(...array_map(static fn (Planting $planting): array => [$planting->getName() => array_map(static fn (Planting $neighbour): string => $neighbour->getName(), $planting->getNeighbours())], $plantings)),
        );
        self::assertSame(
            ['Front garden' => 'Full sun, clay', 'North bed' => 'Shade, clay', 'Terrace' => 'Part shade, loamy'],
            array_merge(...array_map(static fn (Space $space): array => [$space->getName() => $space->describeConditions()], self::getContainer()->get(SpaceRepository::class)->findAllByName())),
        );

        self::assertSame(1, self::getContainer()->get(ReminderChecker::class)->check(self::mockTime('2026-12-01 07:00')->now()));
        $entityManager->flush();
        self::assertSame(
            ['Frost expected on Tuesday 1 December (-3.2 °C): bring Lemon tree indoors. Down to -12.4 °C on Thursday 3 December.'],
            array_map(static fn ($notification): string => $notification->getMessage(), self::getContainer()->get(NotificationRepository::class)->findLatest()),
        );
    }
}
