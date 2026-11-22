<?php

namespace App\Mcp\Tool;

use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\OpenMeteo\Model\DailyMinimum;
use App\DataSource\OpenMeteo\OpenMeteoClient;
use App\Garden\CurrentGarden;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

#[McpTool(
    name: 'get_frost_forecast',
    title: 'Get the frost forecast of the garden',
    description: 'Daily minimum temperatures (°C) forecast at the garden location by Open-Meteo, starting today. Quote "Weather data by Open-Meteo.com" when you use them.',
    annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
    outputSchema: [
        'type' => 'object',
        'properties' => [
            'latitude' => ['type' => 'number'],
            'longitude' => ['type' => 'number'],
            'days' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'format' => 'date'],
                        'min_temperature_c' => ['type' => 'number'],
                    ],
                    'required' => ['date', 'min_temperature_c'],
                ],
            ],
            'attribution' => ['type' => 'string'],
        ],
        'required' => ['latitude', 'longitude', 'days', 'attribution'],
    ],
)]
final readonly class GetFrostForecastTool
{
    public const string ATTRIBUTION = 'Weather data by Open-Meteo.com (CC BY 4.0)';

    public function __construct(
        private OpenMeteoClient $openMeteoClient,
        private CurrentGarden $currentGarden,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Number of days to forecast, today included.', minimum: 1, maximum: OpenMeteoClient::MAX_FORECAST_DAYS)]
        int $days = 3,
    ): array {
        try {
            $forecast = $this->openMeteoClient->getFrostForecast($this->currentGarden->get()->getLocation(), $days);
        } catch (DataSourceUnavailableException $e) {
            throw new ToolCallException($e->getMessage());
        }

        return [
            'latitude' => $forecast->latitude,
            'longitude' => $forecast->longitude,
            'days' => array_map(static fn (DailyMinimum $day): array => [
                'date' => $day->date->format('Y-m-d'),
                'min_temperature_c' => $day->minTemperatureC,
            ], $forecast->days),
            'attribution' => self::ATTRIBUTION,
        ];
    }
}
