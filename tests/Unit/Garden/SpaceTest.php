<?php

namespace App\Tests\Unit\Garden;

use App\Garden\Model\Soil;
use App\Garden\Model\Space;
use App\Plant\Model\Exposure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpaceTest extends TestCase
{
    #[DataProvider('provideItsConditionsInAFewWordsCases')]
    public function testItsConditionsInAFewWords(?Exposure $exposure, ?Soil $soil, ?string $expected): void
    {
        self::assertSame($expected, new Space('North bed', $exposure, $soil, new \DateTimeImmutable('2026-11-01'))->describeConditions());
    }

    /**
     * @return iterable<string, array{?Exposure, ?Soil, ?string}>
     */
    public static function provideItsConditionsInAFewWordsCases(): iterable
    {
        yield 'both' => [Exposure::Sun, Soil::Clay, 'Full sun, clay'];
        yield 'the exposure only' => [Exposure::Shade, null, 'Shade'];
        yield 'the soil only' => [null, Soil::Sandy, 'Sandy soil'];
        yield 'neither' => [null, null, null];
    }

    public function testItIsRevised(): void
    {
        $space = new Space('Balcony', Exposure::Sun, null, new \DateTimeImmutable('2026-11-01'));

        $space->revise('South balcony', Exposure::PartShade, Soil::Loamy);

        self::assertSame('South balcony', $space->getName());
        self::assertSame(Exposure::PartShade, $space->getExposure());
        self::assertSame(Soil::Loamy, $space->getSoil());
        self::assertSame('2026-11-01', $space->getCreatedAt()->format('Y-m-d'), 'Created once.');
    }
}
