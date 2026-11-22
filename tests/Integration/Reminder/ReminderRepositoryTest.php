<?php

namespace App\Tests\Integration\Reminder;

use App\Plant\Model\PlantStatus;
use App\Reminder\Model\Reminder;
use App\Reminder\Model\ReminderType;
use App\Reminder\ReminderRepository;
use App\Tests\Factory\PlantFactory;
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
        $this->reminder('Sage', ReminderType::Frost);
        $this->reminder('Lavender', ReminderType::Pruning);
        $this->reminder('Lavender', ReminderType::Frost);
        $this->reminder('Box', ReminderType::Pruning)->disable();
        $draftReminder = $this->reminder('Draft', ReminderType::Pruning);
        $this->entityManager->flush();
        // A plant back to draft by hand in the database
        $this->entityManager->getConnection()->executeStatement('UPDATE plant SET status = ? WHERE id = ?', [PlantStatus::Draft->value, $draftReminder->getPlant()->getId()]);
        $this->entityManager->clear();

        $reminders = self::getContainer()->get(ReminderRepository::class)->findEnabled();

        self::assertSame(
            [['Lavender', 'frost'], ['Lavender', 'pruning'], ['Sage', 'frost']],
            array_map(static fn (Reminder $reminder): array => [$reminder->getPlant()->getCommonName(), $reminder->getType()->value], $reminders),
        );
    }

    private function reminder(string $plantName, ReminderType $type): Reminder
    {
        $plant = PlantFactory::validated($plantName);
        $reminder = $plant->enableReminder($type);
        $this->entityManager->persist($plant);
        $this->entityManager->flush();

        return $reminder;
    }
}
