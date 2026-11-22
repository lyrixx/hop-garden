<?php

namespace App\Tests\Unit\Plate;

use App\Plate\Model\PlatePart;
use App\Plate\Model\PlateSide;
use App\Plate\PlateCatalog;
use PHPUnit\Framework\TestCase;

final class PlateCatalogTest extends TestCase
{
    public function testAPlantGetsThePlateOfItsSpecies(): void
    {
        $catalog = new PlateCatalog();

        self::assertSame('Hydrangea paniculata grandiflora', $catalog->forSpecies('Hydrangea paniculata')->depicts);
        self::assertSame('Hydrangea hortensia', $catalog->forSpecies('Hydrangea macrophylla')->depicts);
        self::assertSame('Citrus limonum Risso', $catalog->forSpecies('Citrus × limon')->depicts);
        self::assertSame('Lavandula vera D.C.', $catalog->forSpecies("Lavandula angustifolia 'Hidcote'")->depicts);
    }

    public function testAPlantWithoutPlateGetsOneOfItsGenusThenTheSchematicFigure(): void
    {
        $catalog = new PlateCatalog();

        self::assertSame('Rosa canina L.', $catalog->forSpecies('Rosa × odorata')->depicts);
        self::assertTrue($catalog->forSpecies('Fuchsia magellanica')->isSchematic());
        self::assertTrue($catalog->forSpecies('')->isSchematic());
    }

    public function testFiguresAreNumberedDownTheLeftMarginThenDownTheRightOne(): void
    {
        $figures = (new PlateCatalog())->forSpecies('Rosa canina')->figures();

        self::assertSame([1, 2, 3, 4, 5], array_map(static fn ($figure): int => $figure->number, $figures));
        self::assertSame(
            [PlatePart::Bud, PlatePart::Flower, PlatePart::Leaf, PlatePart::Shoot, PlatePart::Stem],
            array_map(static fn ($figure): PlatePart => $figure->part, $figures),
        );
        self::assertSame(
            [PlateSide::Left, PlateSide::Left, PlateSide::Left, PlateSide::Right, PlateSide::Right],
            array_map(static fn ($figure): PlateSide => $figure->side, $figures),
        );
    }

    public function testNumbersInAMarginNeverOverlap(): void
    {
        // The bud of the paniculata sits at the very top of the image, its stem parts close together
        $figures = (new PlateCatalog())->forSpecies('Hydrangea paniculata')->figures();

        foreach ([PlateSide::Left, PlateSide::Right] as $side) {
            $ys = array_values(array_map(static fn ($figure): float => $figure->numberY, array_filter($figures, static fn ($figure): bool => $side === $figure->side)));
            foreach ($ys as $i => $y) {
                self::assertGreaterThanOrEqual(4.0, $y);
                self::assertLessThanOrEqual(96.0, $y);
                if ($i > 0) {
                    self::assertGreaterThanOrEqual(6.5, $y - $ys[$i - 1]);
                }
            }
        }
    }
}
