<?php

namespace App\Tests\Unit\Garden;

use App\Factory\GardenFactory;
use App\Factory\PlantFactory;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Garden\Exception\CareSheetAlreadyLinkedException;
use App\Garden\Model\CareTask;
use App\Garden\Model\Soil;
use App\Plant\Model\Exposure;
use App\Story\DefaultGardenStory;
use App\Tests\Utils\Factory\EntityId;
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

    public function testAPlantAddedFromItsCardKeepsTheKeyOfTheCard(): void
    {
        $plant = PlantFactory::new()->validated()->create(['scientificName' => 'Hydrangea paniculata', 'trefleId' => 191052]);
        $terrace = self::space('Terrace');

        $planting = Planting::proposed('0196a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', 'Hortensia', $plant, $terrace, new \DateTimeImmutable('2026-11-22 09:00'));

        self::assertSame('0196a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', $planting->getProposalId());
        self::assertSame('Hortensia', $planting->getName());
        self::assertSame('Hydrangea paniculata', $planting->getScientificName());
        self::assertSame(191052, $planting->getTrefleId());
        self::assertSame($plant, $planting->getPlant());
        self::assertSame($terrace, $planting->getSpace());
        self::assertSame([$planting], $terrace->getPlantings());
        self::assertSame('2026-11-22 09:00', $planting->getCreatedAt()->format('Y-m-d H:i'));
        self::assertNull($planting->getDiseases());
        self::assertSame([], $planting->getCareRecords());
    }

    public function testADraftIsNotAddedFromACard(): void
    {
        $this->expectException(\LogicException::class);

        Planting::proposed('0196a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', 'Hortensia', EntityId::assign(PlantFactory::createOne(), 3), null, new \DateTimeImmutable('2026-11-22'));
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

        $plant = PlantFactory::createOne();
        $rose->linkTo($plant);

        self::assertSame($plant, $rose->getPlant());
        self::assertFalse($rose->isDrafting());
        self::assertFalse($rose->hasDraftingFailed(), 'Linked: nothing failed any more.');
        self::assertFalse($rose->isDraftedIn($conversationId->toRfc4122()));
    }

    public function testAPlantingWithItsCareSheetIsNotDraftedAgain(): void
    {
        $rose = EntityId::assign(self::planting('Rose'), 12);
        $rose->linkTo(PlantFactory::new()->validated()->create());

        $this->expectException(CareSheetAlreadyLinkedException::class);
        $this->expectExceptionMessageIs('Planting 12 already has its care sheet.');

        $rose->startDrafting(new UuidV7());
    }

    public function testTheGardenInfoIsRevisedButNotTheSpecies(): void
    {
        $planting = new Planting(self::garden(), 'Hydrangea', null, null, new \DateTimeImmutable('2026-11-01'), 191047, 'Hydrangea macrophylla', 'old.png');

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

    public function testAPlantingGrowsInItsOwnGardenOnly(): void
    {
        $planting = self::planting('Rose');
        $elsewhere = GardenFactory::createOne();

        try {
            $planting->revise('Rose', new Space($elsewhere, 'Balcony', null, null, new \DateTimeImmutable('2026-11-01')), null);
            self::fail('A space of another garden.');
        } catch (\LogicException $e) {
            self::assertSame('A planting cannot grow in a space of another garden.', $e->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIs('A planting follows a care sheet of its own garden only.');
        $planting->linkTo(PlantFactory::createOne(['garden' => $elsewhere]));
    }

    /**
     * The one of the factories: PlantFactory makes its care sheets in it.
     */
    private static function garden(): Garden
    {
        $garden = DefaultGardenStory::get('garden');
        self::assertInstanceOf(Garden::class, $garden);

        return $garden;
    }

    private static function planting(string $name, ?Space $space = null): Planting
    {
        return new Planting(self::garden(), $name, $space, null, new \DateTimeImmutable('2026-11-01'));
    }

    private static function space(string $name): Space
    {
        return new Space(self::garden(), $name, Exposure::Shade, Soil::Clay, new \DateTimeImmutable('2026-11-01'));
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
