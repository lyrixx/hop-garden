<?php

namespace App\Reminder\Command;

use App\Garden\Entity\Garden;
use App\Garden\GardenRepository;
use App\Reminder\ReminderChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Whatever the hour, and whether the garden was checked today already: the notifications of a reminder are sent once
 * anyway (once a month for the calendar, once per cold episode for the frost).
 */
#[AsCommand(name: 'app:reminders:run', description: 'Checks the reminders of every garden now, as the scheduler does every day from 7:00')]
final readonly class RunRemindersCommand
{
    public function __construct(
        private GardenRepository $gardenRepository,
        private ReminderChecker $reminderChecker,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Day to simulate (YYYY-MM-DD), checked as if it were 7:00 that day in the timezone of each garden')]
        ?string $date = null,
        #[Option(description: 'The slug of the only garden to check, as in its URL')]
        ?string $garden = null,
    ): int {
        if (null !== $date && false === \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) {
            $io->error(\sprintf('Invalid date "%s", expected YYYY-MM-DD.', $date));

            return 2;
        }

        $gardens = array_values(array_filter($this->gardenRepository->findAllWithMembers(), static fn (Garden $candidate): bool => null === $garden || $candidate->getSlug() === $garden));
        if (null !== $garden && [] === $gardens) {
            $io->error(\sprintf('No garden "%s".', $garden));

            return 2;
        }

        $due = 0;
        foreach ($gardens as $checked) {
            $now = $this->clock->now();
            if (null !== $date) {
                $simulated = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' 07:00', $checked->getLocation()->getTimezone());
                if (false === $simulated || $simulated->format('Y-m-d') !== $date) {
                    $io->error(\sprintf('Invalid date "%s", expected YYYY-MM-DD.', $date));

                    return 2;
                }

                // Every service reading the clock (forecast, recorded responses...) now lives on that day, in the timezone
                // of the server, as the clock of the scheduler
                $now = $simulated->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                Clock::set(new MockClock($now));
            }

            $due += $this->reminderChecker->check($checked, $now);
            $this->entityManager->flush();
        }

        $io->success(\sprintf('%d reminder(s) due in %d garden(s), sent to each of their members.', $due, \count($gardens)));

        return 0;
    }
}
