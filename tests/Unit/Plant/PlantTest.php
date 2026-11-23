<?php

namespace App\Tests\Unit\Plant;

use App\Factory\CareSheetFactory;
use App\Factory\GardenFactory;
use App\Factory\PlantFactory;
use App\Plant\Entity\Plant;
use App\Plant\Exception\PlantAlreadyValidatedException;
use App\Plant\Exception\PlantNotValidatedException;
use App\Plant\Model\PlantStatus;
use App\Reminder\Model\ReminderType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class PlantTest extends TestCase
{
    public function testAProposedPlantIsADraft(): void
    {
        $plant = Plant::propose(GardenFactory::createOne(), 'Panicled hydrangea', 'Hydrangea paniculata', 'hydrangea-paniculata', 'Hydrangeaceae', CareSheetFactory::createOne(), new \DateTimeImmutable('2026-11-21 09:55'), 191052, 'https://example.com/paniculata.jpg', 'Grows fast.');

        self::assertSame(PlantStatus::Draft, $plant->getStatus());
        self::assertFalse($plant->isValidated());
        self::assertNull($plant->getValidatedAt());
        self::assertSame(191052, $plant->getTrefleId());
        self::assertSame('https://example.com/paniculata.jpg', $plant->getImageUrl());
        self::assertSame('Grows fast.', $plant->getNotes());
        self::assertSame([], $plant->getReminders());
        self::assertSame('hydrangea-paniculata', $plant->getSlug());
    }

    public function testTheUserValidatesItOnce(): void
    {
        $plant = PlantFactory::createOne();

        $plant->validate(new \DateTimeImmutable('2026-11-21 10:00'));

        self::assertSame(PlantStatus::Validated, $plant->getStatus());
        self::assertSame('2026-11-21 10:00', $plant->getValidatedAt()?->format('Y-m-d H:i'));

        $this->expectException(PlantAlreadyValidatedException::class);
        $this->expectExceptionMessageIs(\sprintf('Plant %s is already validated.', $plant->getId()));

        $plant->validate(new \DateTimeImmutable('2026-11-21 10:01'));
    }

    public function testADraftHasNoReminder(): void
    {
        $plant = PlantFactory::createOne();

        $this->expectException(PlantNotValidatedException::class);
        $this->expectExceptionMessageIs(\sprintf('Plant %s is still a draft: the user must validate it before reminders can be set.', $plant->getId()));

        $plant->enableReminder(ReminderType::Pruning);
    }

    public function testADraftHasNoReminderToDisableEither(): void
    {
        $plant = PlantFactory::createOne();

        $this->expectException(PlantNotValidatedException::class);

        $plant->disableReminder(ReminderType::Pruning);
    }

    public function testAReminderIsCreatedOnceThenToggled(): void
    {
        $plant = PlantFactory::new()->validated()->create();

        $frost = $plant->enableReminder(ReminderType::Frost);
        $plant->enableReminder(ReminderType::Pruning);
        $plant->disableReminder(ReminderType::Frost);

        self::assertSame([ReminderType::Pruning], $plant->getEnabledReminderTypes());
        self::assertFalse($plant->hasEnabledReminder(ReminderType::Frost));
        self::assertSame($frost, $plant->enableReminder(ReminderType::Frost), 'The same reminder, enabled again.');
        self::assertCount(2, $plant->getReminders());
        self::assertSame([ReminderType::Pruning, ReminderType::Frost], $plant->getEnabledReminderTypes(), 'In the order of the reminder types.');
    }

    public function testDisablingAReminderNeverEnabledChangesNothing(): void
    {
        $plant = PlantFactory::new()->validated()->create();

        $plant->disableReminder(ReminderType::Cutting);

        self::assertSame([], $plant->getReminders());
    }

    public function testAValidatedPlantStaysValidatedOnceRevised(): void
    {
        $plant = PlantFactory::new()->validated()->create();
        $careSheet = CareSheetFactory::createOne(['minTemperatureC' => -20]);

        $plant->revise('Hortensia', $careSheet, 'Moved to the shed.');

        self::assertTrue($plant->isValidated());
        self::assertSame('Hortensia', $plant->getCommonName());
        self::assertSame($careSheet, $plant->getCareSheet());
        self::assertSame('Moved to the shed.', $plant->getNotes());
    }

    public function testItsIdIsAUuidV7OfItsCreation(): void
    {
        $plant = PlantFactory::createOne();

        self::assertInstanceOf(UuidV7::class, $plant->getId(), 'Before any flush.');
        self::assertFalse($plant->getId()->equals(PlantFactory::createOne()->getId()));
    }
}
