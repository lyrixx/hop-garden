<?php

namespace App\Reminder\Command;

use App\Locale\PreferredLocale;
use App\Reminder\ReminderChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\LocaleSwitcher;

#[AsCommand(name: 'app:reminders:run', description: 'Checks the reminders now, as the scheduler does every day at 7:00')]
final readonly class RunRemindersCommand
{
    public function __construct(
        private ReminderChecker $reminderChecker,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        #[Autowire(env: 'GARDEN_TIMEZONE')]
        private string $gardenTimezone,
        private LocaleSwitcher $localeSwitcher,
        private PreferredLocale $preferredLocale,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Day to simulate (YYYY-MM-DD), checked as if it were 7:00 that day')]
        ?string $date = null,
    ): int {
        $now = $this->clock->now();

        if (null !== $date) {
            $simulated = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' 07:00', new \DateTimeZone($this->gardenTimezone));
            if (false === $simulated || $simulated->format('Y-m-d') !== $date) {
                $io->error(\sprintf('Invalid date "%s", expected YYYY-MM-DD.', $date));

                return 2;
            }

            // Every service reading the clock (forecast, recorded responses...) now lives on that day
            Clock::set(new MockClock($simulated));
            $now = $simulated;
        }

        // As the scheduler does: see CheckRemindersHandler
        $sent = $this->localeSwitcher->runWithLocale($this->preferredLocale->get()->value, function () use ($now): int {
            $sent = $this->reminderChecker->check($now);
            $this->entityManager->flush();

            return $sent;
        });

        $io->success(\sprintf('%d notification(s) sent for %s.', $sent, $now->format('l j F Y')));

        return 0;
    }
}
