<?php

namespace App\Mcp\Tool;

use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\OpenMeteo\Model\DailyMinimum;
use App\DataSource\OpenMeteo\OpenMeteoClient;
use App\Garden\CurrentGarden;
use App\Garden\Entity\Garden;
use App\Reminder\CalendarSchedule;
use App\Reminder\FrostRiskEvaluator;
use App\Reminder\Model\ReminderType;
use App\Reminder\Model\UpcomingFrost;
use App\Reminder\Model\UpcomingReminder;
use App\Reminder\ReminderChecker;
use App\Reminder\ReminderRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;

#[McpTool(
    name: 'upcoming_reminders',
    title: 'List the upcoming reminders',
    description: 'The calendar reminders (pruning, deadheading, cuttings) due in the coming days for the validated plants, and the frost risk of the next 3 days for the plants whose frost reminder is enabled.',
    annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
)]
final readonly class UpcomingRemindersTool
{
    public function __construct(
        private ReminderRepository $reminderRepository,
        private CalendarSchedule $calendarSchedule,
        private FrostRiskEvaluator $frostRiskEvaluator,
        private OpenMeteoClient $openMeteoClient,
        private ClockInterface $clock,
        private CurrentGarden $currentGarden,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'How many days ahead to look for calendar reminders.', minimum: 1, maximum: 366)]
        int $days = 30,
    ): array {
        $garden = $this->currentGarden->get();
        $reminders = $this->reminderRepository->findEnabled($garden);
        $today = $this->clock->now();

        $calendar = array_map(static fn (UpcomingReminder $upcoming): array => [
            'plant_id' => $upcoming->reminder->getPlant()->getId()->toRfc4122(),
            'plant' => $upcoming->reminder->getPlant()->getCommonName(),
            'type' => $upcoming->reminder->getType()->value,
            'starts_on' => $upcoming->startsOn->format('Y-m-d'),
        ], $this->calendarSchedule->upcoming($reminders, $today, $days));

        $frostReminders = array_values(array_filter($reminders, static fn ($reminder): bool => ReminderType::Frost === $reminder->getType()));

        return [
            'calendar' => $calendar,
            'frost' => $this->frostRisks($garden, $frostReminders),
        ];
    }

    /**
     * @param list<\App\Reminder\Entity\Reminder> $frostReminders
     *
     * @return array<string, mixed>
     */
    private function frostRisks(Garden $garden, array $frostReminders): array
    {
        if ([] === $frostReminders) {
            return ['plants_at_risk' => [], 'forecast_available' => true];
        }

        try {
            $forecast = $this->openMeteoClient->getFrostForecast($garden->getLocation(), ReminderChecker::FROST_FORECAST_DAYS);
        } catch (DataSourceUnavailableException) {
            return ['plants_at_risk' => [], 'forecast_available' => false];
        }

        $atRisk = array_map(static fn (UpcomingFrost $upcoming): array => [
            'plant_id' => $upcoming->reminder->getPlant()->getId()->toRfc4122(),
            'plant' => $upcoming->reminder->getPlant()->getCommonName(),
            'threshold_c' => $upcoming->risk->thresholdC,
            'frost_days' => array_map(static fn (DailyMinimum $day): array => [
                'date' => $day->date->format('Y-m-d'),
                'min_temperature_c' => $day->minTemperatureC,
            ], $upcoming->risk->days),
        ], $this->frostRiskEvaluator->upcoming($frostReminders, $forecast));

        return [
            'plants_at_risk' => $atRisk,
            'forecast_available' => true,
            'attribution' => GetFrostForecastTool::ATTRIBUTION,
        ];
    }
}
