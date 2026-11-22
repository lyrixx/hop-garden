<?php

namespace App\Reminder;

use App\Reminder\Model\FrostRisk;
use App\Reminder\Model\Reminder;
use App\Reminder\Model\ReminderType;

final readonly class ReminderMessages
{
    private const string DATE_FORMAT = 'l j F';

    public function forCalendar(Reminder $reminder): string
    {
        $plant = $reminder->getPlant();
        $careSheet = $plant->getCareSheet();

        $message = match ($reminder->getType()) {
            ReminderType::Pruning => \sprintf('Time to prune %s. %s', $plant->getCommonName(), $careSheet->getPruningAdvice()),
            ReminderType::Deadheading => \sprintf('Time to deadhead %s: cut the faded flowers.', $plant->getCommonName()),
            ReminderType::Cutting => \sprintf('Time to take cuttings of %s. %s', $plant->getCommonName(), $careSheet->getCuttingAdvice()),
            ReminderType::Frost => throw new \LogicException('A frost reminder is not a calendar reminder.'),
        };

        return trim($message);
    }

    public function forFrost(Reminder $reminder, FrostRisk $risk): string
    {
        $plant = $reminder->getPlant();
        $first = $risk->firstDay();

        $action = $plant->getCareSheet()->isFrostSensitive()
            ? \sprintf('bring %s indoors', $plant->getCommonName())
            : \sprintf('cover %s', $plant->getCommonName());

        $message = \sprintf('Frost expected on %s (%s °C): %s.', $first->date->format(self::DATE_FORMAT), $this->formatTemperature($first->minTemperatureC), $action);

        $coldest = $first;
        foreach ($risk->days as $day) {
            if ($day->minTemperatureC < $coldest->minTemperatureC) {
                $coldest = $day;
            }
        }

        if ($coldest !== $first) {
            $message .= \sprintf(' Down to %s °C on %s.', $this->formatTemperature($coldest->minTemperatureC), $coldest->date->format(self::DATE_FORMAT));
        }

        return $message;
    }

    private function formatTemperature(float $temperatureC): string
    {
        return number_format($temperatureC, 1);
    }
}
