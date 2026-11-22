<?php

namespace App\Reminder;

use App\Locale\IntlFormatter;
use App\Reminder\Entity\Reminder;
use App\Reminder\Model\FrostRisk;
use App\Reminder\Model\ReminderType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The text of a notification, in the language of the translator: the caller switches to the one of the recipient.
 */
final readonly class ReminderMessages
{
    public function __construct(
        private TranslatorInterface $translator,
        private IntlFormatter $formatter,
    ) {
    }

    public function forCalendar(Reminder $reminder): string
    {
        $plant = $reminder->getPlant();
        $careSheet = $plant->getCareSheet();
        $name = ['name' => $plant->getCommonName()];

        $message = match ($reminder->getType()) {
            ReminderType::Pruning => $this->translator->trans('reminder.message.pruning', $name) . ' ' . $careSheet->getPruningAdvice(),
            ReminderType::Deadheading => $this->translator->trans('reminder.message.deadheading', $name),
            ReminderType::Cutting => $this->translator->trans('reminder.message.cutting', $name) . ' ' . $careSheet->getCuttingAdvice(),
            ReminderType::Frost => throw new \LogicException('A frost reminder is not a calendar reminder.'),
        };

        return trim($message);
    }

    public function forFrost(Reminder $reminder, FrostRisk $risk): string
    {
        $plant = $reminder->getPlant();
        $first = $risk->firstDay();

        $message = $this->translator->trans($plant->getCareSheet()->isFrostSensitive() ? 'reminder.message.frost_indoors' : 'reminder.message.frost_cover', [
            'date' => $this->formatter->dayAndMonth($first->date),
            'temperature' => $this->formatter->temperature($first->minTemperatureC),
            'name' => $plant->getCommonName(),
        ]);

        $coldest = $first;
        foreach ($risk->days as $day) {
            if ($day->minTemperatureC < $coldest->minTemperatureC) {
                $coldest = $day;
            }
        }

        if ($coldest !== $first) {
            $message .= ' ' . $this->translator->trans('reminder.message.frost_coldest', [
                'temperature' => $this->formatter->temperature($coldest->minTemperatureC),
                'date' => $this->formatter->dayAndMonth($coldest->date),
            ]);
        }

        return $message;
    }
}
