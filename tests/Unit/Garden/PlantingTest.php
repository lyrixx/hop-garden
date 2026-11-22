<?php

namespace App\Tests\Unit\Garden;

use App\Garden\Exception\CareSheetAlreadyLinkedException;
use App\Garden\Model\CareTask;
use App\Garden\Model\Planting;
use App\Garden\Model\Soil;
use App\Garden\Model\Space;
use App\Plant\Model\Exposure;
use App\Plant\Model\Plant;
use App\Tests\Factory\EntityId;
use App\Tests\Factory\PlantFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class PlantingTest extends TestCase
{
    public function testTheNeighboursAreTheOtherPlantingsOfItsSpace(): void
    {
        $northBed = self::space('North bed');
        $rose = self::planting('Rose', $northBed);
        $lavender = self::planting('Lavender', $northBed);
        $sage = self::planting('Sage', $northBed);
        self::planting('Thyme', self::space('Balcony'));

        self::assertSame(['Lavender', 'Sage'], self::names($rose->getNeighbours()));
        self::assertSame(['Rose', 'Sage'], self::names($lavender->getNeighbours()));
        self::assertSame(['Lavender', 'Rose', 'Sage'], self::names($northBed->getPlantings()));
    }

    public function testWithoutASpaceAPlantingHasNoNeighbour(): void
    {
        self::assertSame([], self::planting('Rose')->getNeighbours());
    }

    public function testMovingToAnotherSpaceChangesTheNeighbours(): void
    {
        $northBed = self::space('North bed');
        $balcony = self::space('Balcony');
        $rose = self::planting('Rose', $northBed);
        $lavender = self::planting('Lavender', $northBed);
        $thyme = self::planting('Thyme', $balcony);

        $rose->revise('Rose', $balcony, null);

        self::assertSame($balcony, $rose->getSpace());
        self::assertSame(['Thyme'], self::names($rose->getNeighbours()));
        self::assertSame(['Rose'], self::names($thyme->getNeighbours()));
        self::assertSame([], self::names($lavender->getNeighbours()));
        self::assertSame(['Lavender'], self::names($northBed->getPlantings()));
    }

    public function testTheLastTimeACareTaskWasDone(): void
    {
        $rose = self::planting('Rose');
        $rose->recordCare(CareTask::Pruning, new \DateTimeImmutable('2026-03-10'));
        $rose->recordCare(CareTask::Watering, new \DateTimeImmutable('2026-07-01'));
        $rose->recordCare(CareTask::Pruning, new \DateTimeImmutable('2025-02-20'));

        self::assertSame('2026-03-10', $rose->lastDone(CareTask::Pruning)?->format('Y-m-d'));
        self::assertNull($rose->lastDone(CareTask::FrostProtection));
        self::assertSame(['2025-02-20', '2026-03-10', '2026-07-01'], array_map(static fn ($record): string => $record->doneOn->format('Y-m-d'), $rose->getCareRecords()));
    }

    public function testAPlantValidatedFromTheChatJoinsTheGarden(): void
    {
        $plant = Plant::propose('Panicled hydrangea', 'Hydrangea paniculata', 'Hydrangeaceae', PlantFactory::careSheet(), new \DateTimeImmutable('2026-11-21 09:55'), 191052);
        $plant->validate(new \DateTimeImmutable('2026-11-21 10:00'));

        $planting = Planting::forValidatedPlant($plant);

        self::assertSame('Panicled hydrangea', $planting->getName());
        self::assertSame('Hydrangea paniculata', $planting->getScientificName());
        self::assertSame(191052, $planting->getTrefleId());
        self::assertSame($plant, $planting->getPlant());
        self::assertSame('2026-11-21 10:00', $planting->getCreatedAt()->format('Y-m-d H:i'));
        self::assertNull($planting->getSpace());
        self::assertNull($planting->getDiseases());
        self::assertSame([], $planting->getCareRecords());
    }

    public function testADraftDoesNotJoinTheGarden(): void
    {
        $this->expectException(\LogicException::class);

        Planting::forValidatedPlant(PlantFactory::draft());
    }

    public function testTheSameCareOnTheSameDayIsRecordedOnce(): void
    {
        $rose = self::planting('Rose');
        $rose->recordCare(CareTask::Pruning, new \DateTimeImmutable('2026-03-10'));
        $rose->recordCare(CareTask::Pruning, new \DateTimeImmutable('2026-03-10 18:00'));
        $rose->recordCare(CareTask::Watering, new \DateTimeImmutable('2026-03-10'));

        self::assertSame([['pruning', '2026-03-10'], ['watering', '2026-03-10']], array_map(static fn ($record): array => [$record->task->value, $record->doneOn->format('Y-m-d')], $rose->getCareRecords()));
    }

    public function testNeighboursAreListedByName(): void
    {
        $northBed = self::space('North bed');
        $rose = self::planting('rose', $northBed);
        self::planting('Sage', $northBed);
        self::planting('lavender', $northBed);
        self::planting('Box', $northBed);

        self::assertSame(['Box', 'lavender', 'Sage'], self::names($rose->getNeighbours()));
    }

    public function testADraftingAttemptStartsInItsConversation(): void
    {
        $rose = self::planting('Rose');
        self::assertFalse($rose->isDrafting());
        self::assertFalse($rose->hasDraftingFailed());

        $conversationId = new UuidV7();
        $rose->startDrafting($conversationId);

        self::assertTrue($rose->isDrafting());
        self::assertSame($conversationId->toRfc4122(), $rose->getConversationId());
        self::assertTrue($rose->isDraftedIn($conversationId->toRfc4122()));
        self::assertFalse($rose->isDraftedIn((new UuidV7())->toRfc4122()));
    }

    public function testAFailedAttemptIsReplacedByARetry(): void
    {
        $rose = self::planting('Rose');
        $failed = new UuidV7();
        $rose->startDrafting($failed);
        $rose->failDrafting();

        self::assertFalse($rose->isDrafting());
        self::assertTrue($rose->hasDraftingFailed());
        self::assertFalse($rose->isDraftedIn($failed->toRfc4122()), 'A failed attempt is over.');

        $retry = new UuidV7();
        $rose->startDrafting($retry);

        self::assertTrue($rose->isDrafting());
        self::assertFalse($rose->hasDraftingFailed());
        self::assertFalse($rose->isDraftedIn($failed->toRfc4122()), 'The previous attempt is stale.');
        self::assertTrue($rose->isDraftedIn($retry->toRfc4122()));
    }

    public function testALinkedCareSheetEndsTheDrafting(): void
    {
        $rose = self::planting('Rose');
        $conversationId = new UuidV7();
        $rose->startDrafting($conversationId);
        $rose->failDrafting();

        $plant = PlantFactory::draft();
        $rose->linkTo($plant);

        self::assertSame($plant, $rose->getPlant());
        self::assertFalse($rose->isDrafting());
        self::assertFalse($rose->hasDraftingFailed(), 'Linked: nothing failed any more.');
        self::assertFalse($rose->isDraftedIn($conversationId->toRfc4122()));
    }

    public function testAPlantingWithItsCareSheetIsNotDraftedAgain(): void
    {
        $rose = EntityId::assign(self::planting('Rose'), 12);
        $rose->linkTo(PlantFactory::validated());

        $this->expectException(CareSheetAlreadyLinkedException::class);
        $this->expectExceptionMessageIs('Planting 12 already has its care sheet.');

        $rose->startDrafting(new UuidV7());
    }

    public function testTheGardenInfoIsRevisedButNotTheSpecies(): void
    {
        $planting = new Planting('Hydrangea', null, null, new \DateTimeImmutable('2026-11-01'), 191047, 'Hydrangea macrophylla', 'old.png');

        $shed = self::space('By the shed');

        $planting->revise('Hydrangea by the shed', $shed, 'Powdery mildew.');
        $planting->replacePhoto('new.png');

        self::assertSame('Hydrangea by the shed', $planting->getName());
        self::assertSame($shed, $planting->getSpace());
        self::assertSame([$planting], $shed->getPlantings());
        self::assertSame('Powdery mildew.', $planting->getDiseases());
        self::assertSame('new.png', $planting->getPhotoFilename());
        self::assertSame('Hydrangea macrophylla', $planting->getScientificName());
        self::assertSame(191047, $planting->getTrefleId());
    }

    private static function planting(string $name, ?Space $space = null): Planting
    {
        return new Planting($name, $space, null, new \DateTimeImmutable('2026-11-01'));
    }

    private static function space(string $name): Space
    {
        return new Space($name, Exposure::Shade, Soil::Clay, new \DateTimeImmutable('2026-11-01'));
    }

    /**
     * @param list<Planting> $plantings
     *
     * @return list<string>
     */
    private static function names(array $plantings): array
    {
        return array_map(static fn (Planting $planting): string => $planting->getName(), $plantings);
    }
}
