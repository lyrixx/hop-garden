<?php

namespace App\Reminder;

use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\OpenMeteo\OpenMeteoClient;
use App\Reminder\Model\Reminder;
use App\Reminder\Notifier\Model\ReminderNotification;
use Psr\Log\LoggerInterface;
use Symfony\Component\Notifier\NotifierInterface;

/**
 * Sends the notifications of the reminders due on a given day. The caller flushes.
 */
final readonly class ReminderChecker
{
    public const int FROST_FORECAST_DAYS = 3;

    public function __construct(
        private ReminderRepository $reminderRepository,
        private OpenMeteoClient $openMeteoClient,
        private FrostRiskEvaluator $frostRiskEvaluator,
        private ReminderMessages $messages,
        private NotifierInterface $notifier,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int the number of notifications sent
     */
    public function check(\DateTimeImmutable $now): int
    {
        $sent = 0;
        $frostReminders = [];

        foreach ($this->reminderRepository->findEnabled() as $reminder) {
            if (!$reminder->getType()->isCalendar()) {
                $frostReminders[] = $reminder;

                continue;
            }

            $months = $reminder->getType()->monthsIn($reminder->getPlant()->getCareSheet());
            if (!\in_array((int) $now->format('n'), $months, true) || $reminder->wasTriggeredInMonthOf($now)) {
                continue;
            }

            $reminder->markTriggered($now);
            $this->send($reminder, $this->messages->forCalendar($reminder), $now);
            ++$sent;
        }

        if ([] === $frostReminders) {
            return $sent;
        }

        try {
            $forecast = $this->openMeteoClient->getFrostForecast(self::FROST_FORECAST_DAYS);
        } catch (DataSourceUnavailableException $e) {
            $this->logger->warning('Frost reminders skipped: no forecast.', ['exception' => $e]);

            return $sent;
        }

        foreach ($frostReminders as $reminder) {
            $risk = $this->frostRiskEvaluator->evaluate($reminder->getPlant()->getCareSheet(), $forecast);
            if (null === $risk) {
                continue;
            }

            if (!$reminder->recordFrostEpisode($risk->firstDay()->date, $risk->lastDay()->date, $now)) {
                continue;
            }

            $this->send($reminder, $this->messages->forFrost($reminder, $risk), $now);
            ++$sent;
        }

        return $sent;
    }

    private function send(Reminder $reminder, string $message, \DateTimeImmutable $now): void
    {
        $this->notifier->send(new ReminderNotification($reminder, $message, $now));
    }
}
