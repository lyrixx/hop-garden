<?php

namespace App\Reminder;

use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\OpenMeteo\OpenMeteoClient;
use App\Garden\Entity\Garden;
use App\Locale\PreferredLocale;
use App\Reminder\Entity\Reminder;
use App\Reminder\Notifier\Model\MemberRecipient;
use App\Reminder\Notifier\Model\ReminderNotification;
use Psr\Log\LoggerInterface;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Sends the notifications of the reminders of a garden due on a given day, with the frost forecast at the garden: one
 * per member, written in their language. The caller flushes.
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
        private LocaleSwitcher $localeSwitcher,
        private PreferredLocale $preferredLocale,
    ) {
    }

    /**
     * @return int the number of reminders due, each sent to every member
     */
    public function check(Garden $garden, \DateTimeImmutable $now): int
    {
        $due = 0;
        $frostReminders = [];
        // The month of the garden, in its timezone
        $localNow = $garden->localTime($now);

        foreach ($this->reminderRepository->findEnabled($garden) as $reminder) {
            if (!$reminder->getType()->isCalendar()) {
                $frostReminders[] = $reminder;

                continue;
            }

            $months = $reminder->getType()->monthsIn($reminder->getPlant()->getCareSheet());
            if (!\in_array((int) $localNow->format('n'), $months, true) || $reminder->wasTriggeredInMonthOf($localNow)) {
                continue;
            }

            $reminder->markTriggered($now);
            $this->sendToEveryMember($garden, $reminder, fn (): string => $this->messages->forCalendar($reminder), $now);
            ++$due;
        }

        if ([] === $frostReminders) {
            return $due;
        }

        try {
            $forecast = $this->openMeteoClient->getFrostForecast($garden->getLocation(), self::FROST_FORECAST_DAYS);
        } catch (DataSourceUnavailableException $e) {
            $this->logger->warning('Frost reminders skipped: no forecast.', ['exception' => $e, 'garden_id' => $garden->getId()]);

            return $due;
        }

        foreach ($this->frostRiskEvaluator->upcoming($frostReminders, $forecast) as $upcoming) {
            $reminder = $upcoming->reminder;
            if (!$reminder->recordFrostEpisode($upcoming->risk->firstDay()->date, $upcoming->risk->lastDay()->date, $now)) {
                continue;
            }

            $this->sendToEveryMember($garden, $reminder, fn (): string => $this->messages->forFrost($reminder, $upcoming->risk), $now);
            ++$due;
        }

        return $due;
    }

    /**
     * @param callable(): string $message written in the language of the translator
     */
    private function sendToEveryMember(Garden $garden, Reminder $reminder, callable $message, \DateTimeImmutable $now): void
    {
        foreach ($garden->getMembers() as $member) {
            $text = $this->localeSwitcher->runWithLocale($this->preferredLocale->of($member)->value, $message);
            $this->notifier->send(new ReminderNotification($reminder, $text, $now), new MemberRecipient($member));
        }
    }
}
