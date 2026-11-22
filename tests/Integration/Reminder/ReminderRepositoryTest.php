<?php

namespace App\Tests\Integration\Reminder;

use App\Factory\PlantFactory;
use App\Factory\ReminderFactory;
use App\Garden\Entity\Garden;
use App\Plant\Model\PlantStatus;
use App\Reminder\Entity\Reminder;
use App\Reminder\Model\ReminderType;
use App\Reminder\ReminderRepository;
use App\Story\DefaultGardenStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReminderRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTheEnabledRemindersOfTheValidatedPlants(): void
    {
        self::reminder('Sage', ReminderType::Frost)->create();
        self::reminder('Lavender', ReminderType::Pruning)->create();
        self::reminder('Lavender', ReminderType::Frost)->create();
        self::reminder('Box', ReminderType::Pruning)->disabled()->create();
        $draftReminder = self::reminder('Draft', ReminderType::Pruning)->create();
        // A plant back to draft by hand in the database
        $this->entityManager->getConnection()->executeStatement('UPDATE plant SET status = ? WHERE id = ?', [PlantStatus::Draft->value, $draftReminder->getPlant()->getId()]);
        $this->entityManager->clear();

        $reminders = self::getContainer()->get(ReminderRepository::class)->findEnabled(self::garden());

        self::assertSame(
            [['Lavender', 'frost'], ['Lavender', 'pruning'], ['Sage', 'frost']],
            array_map(static fn (Reminder $reminder): array => [$reminder->getPlant()->getCommonName(), $reminder->getType()->value], $reminders),
        );
    }

    private static function reminder(string $plantName, ReminderType $type): ReminderFactory
    {
        return ReminderFactory::new(['plant' => PlantFactory::new()->validated()->with(['commonName' => $plantName]), 'type' => $type]);
    }

    private static function garden(): Garden
    {
        $garden = DefaultGardenStory::get('garden');
        self::assertInstanceOf(Garden::class, $garden);

        return $garden;
    }
}
