<?php

namespace App\Tests\Integration\Mcp;

use App\Tests\Utils\Http\UnavailableDataSources;
use App\Tests\Utils\Mcp\McpTestCase;
use Mcp\Exception\RequestException;
use PHPUnit\Framework\Attributes\TestWith;

final class DataSourceToolsTest extends McpTestCase
{
    public function testSearchPlantReturnsTheCandidatesOfAnAmbiguousName(): void
    {
        $result = $this->callTool('search_plant', ['query' => 'hortensia']);

        self::assertSame(
            ['Hydrangea macrophylla', 'Hydrangea paniculata', 'Hydrangea arborescens'],
            array_column($result['candidates'], 'scientific_name'),
        );
        self::assertSame('Hydrangeaceae', $result['candidates'][1]['family']);
        self::assertIsInt($result['candidates'][1]['trefle_id']);
    }

    public function testSearchPlantReturnsNothingForAnUnknownName(): void
    {
        self::assertSame(['candidates' => []], $this->callTool('search_plant', ['query' => 'triffid']));
    }

    public function testSearchPlantRejectsATooShortQuery(): void
    {
        $this->expectException(RequestException::class);

        $this->mcp()->callTool('search_plant', ['query' => 'a']);
    }

    public function testGetFrostForecastStartsToday(): void
    {
        self::mockTime('2026-12-03 07:00');

        $result = $this->callTool('get_frost_forecast', ['days' => 3]);

        self::assertSame(
            [
                ['date' => '2026-12-03', 'min_temperature_c' => -3.2],
                ['date' => '2026-12-04', 'min_temperature_c' => -8.5],
                ['date' => '2026-12-05', 'min_temperature_c' => -12.4],
            ],
            $result['days'],
        );
        self::assertStringContainsString('Open-Meteo', $result['attribution']);
    }

    public function testSearchPlantRejectsATooLongQuery(): void
    {
        $this->expectException(RequestException::class);

        $this->mcp()->callTool('search_plant', ['query' => str_repeat('a', 101)]);
    }

    public function testSearchPlantTellsTheModelToGoOnWithoutTrefle(): void
    {
        UnavailableDataSources::trefle(self::getContainer());

        self::assertSame(
            'Trefle did not answer correctly. Continue with your own knowledge and give a low confidence to every value.',
            $this->callToolExpectingError('search_plant', ['query' => 'hortensia']),
        );
    }

    public function testGetFrostForecastLastsThreeDaysByDefault(): void
    {
        self::mockTime('2026-12-03 07:00');

        $result = $this->callTool('get_frost_forecast');

        self::assertSame(['2026-12-03', '2026-12-04', '2026-12-05'], array_column($result['days'], 'date'));
        self::assertSame([48.9, 2.3], [$result['latitude'], $result['longitude']], 'At the garden of the test, in Paris, rounded.');
        self::assertSame('Weather data by Open-Meteo.com (CC BY 4.0)', $result['attribution']);
    }

    #[TestWith([0])]
    #[TestWith([17])]
    public function testGetFrostForecastRejectsALengthOpenMeteoDoesNotForecast(int $days): void
    {
        $this->expectException(RequestException::class);

        $this->mcp()->callTool('get_frost_forecast', ['days' => $days]);
    }

    public function testGetFrostForecastFailsWithoutOpenMeteo(): void
    {
        UnavailableDataSources::openMeteo(self::getContainer());

        self::assertSame('Open-Meteo did not answer correctly.', $this->callToolExpectingError('get_frost_forecast', ['days' => 3]));
    }
}
