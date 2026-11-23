<?php

namespace App\Tests\Integration\Garden;

use App\Factory\GardenFactory;
use App\Factory\InvitationFactory;
use App\Factory\UserFactory;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Invitation;
use App\Garden\InvitationRepository;
use App\Garden\Scheduler\PurgeEndedInvitations;
use App\Reminder\Entity\Notification;
use App\Reminder\NotificationRepository;
use App\Reminder\Scheduler\ReminderSchedule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * An invitation is deleted 30 days after it ends, with its address: expired, accepted or revoked. The one still pending
 * stays, expired or not, as long as it is not 30 days past its expiration.
 */
final class PurgeEndedInvitationsTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string NOW = '2026-12-31 03:00';

    public function testAnInvitationGoes30DaysAfterItEnds(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
        $garden = GardenFactory::createOne();
        self::invite($garden, 'pending@example.com', '2026-12-20 10:00');
        self::invite($garden, 'expired-29-days-ago@example.com', self::daysAgo(29 + 14));
        self::invite($garden, 'expired-31-days-ago@example.com', self::daysAgo(31 + 14));
        $acceptedLongAgo = self::invite($garden, 'accepted-31-days-ago@example.com', self::daysAgo(32), accepted: self::daysAgo(31));
        $acceptedLately = self::invite($garden, 'accepted-29-days-ago@example.com', self::daysAgo(30), accepted: self::daysAgo(29));
        self::invite($garden, 'revoked-29-days-ago@example.com', self::daysAgo(30), revoked: self::daysAgo(29));
        self::invite($garden, 'revoked-31-days-ago@example.com', self::daysAgo(32), revoked: self::daysAgo(31));
        self::invite($garden, 'revoked-once-expired@example.com', self::daysAgo(31 + 14), revoked: self::daysAgo(10));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ([$acceptedLongAgo, $acceptedLately] as $invitation) {
            $entityManager->persist(Notification::newMember($garden->getOwner(), $invitation, \sprintf('Somebody joined, with the invitation sent to %s.', $invitation->getEmail()), $invitation->getAcceptedAt() ?? self::fail()));
        }
        $entityManager->flush();

        self::getContainer()->get(PurgeEndedInvitations::class)();

        // Read from the database: the DELETE skips the entity manager
        $emails = array_map(static fn (Invitation $invitation): string => $invitation->getEmail(), self::getContainer()->get(InvitationRepository::class)->findAll());
        sort($emails);
        self::assertSame(['accepted-29-days-ago@example.com', 'expired-29-days-ago@example.com', 'pending@example.com', 'revoked-29-days-ago@example.com'], $emails);
        self::assertSame(
            ['Somebody joined, with the invitation sent to accepted-29-days-ago@example.com.'],
            array_map(static fn (Notification $notification): string => $notification->getMessage(), self::getContainer()->get(NotificationRepository::class)->findLatest($garden->getOwner(), $garden)),
            'The notification of a member who joined goes with its invitation, and the address it quotes.',
        );
    }

    public function testTheWorkerPurgesEveryDayAt3InTheMorning(): void
    {
        self::bootKernel();
        $purges = array_values(array_filter(
            self::getContainer()->get(ReminderSchedule::class)->getSchedule()->getRecurringMessages(),
            static fn (RecurringMessage $recurringMessage): bool => array_any(self::messagesOf($recurringMessage), static fn (object $message): bool => $message instanceof ServiceCallMessage && PurgeEndedInvitations::class === $message->getServiceId()),
        ));

        self::assertCount(1, $purges, 'In the schedule the worker consumes, scheduler_default.');
        $trigger = $purges[0]->getTrigger();
        self::assertSame('every 1 day', (string) $trigger);
        $run = $trigger->getNextRunDate(new \DateTimeImmutable('2027-03-01 12:00', new \DateTimeZone('UTC'))) ?? self::fail('It never runs.');
        self::assertSame('2027-03-02 03:00', $run->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
    }

    private static function invite(Garden $garden, string $email, string $sentAt, ?string $accepted = null, ?string $revoked = null): Invitation
    {
        $factory = InvitationFactory::new();
        if (null !== $accepted) {
            $factory = $factory->accepted(UserFactory::new()->approved()->create(), new \DateTimeImmutable($accepted));
        }
        if (null !== $revoked) {
            $factory = $factory->revoked(new \DateTimeImmutable($revoked));
        }

        return $factory->create(['garden' => $garden, 'email' => $email, 'sentAt' => new \DateTimeImmutable($sentAt)]);
    }

    private static function daysAgo(int $days): string
    {
        return new \DateTimeImmutable(self::NOW)->modify("-{$days} days")->format('Y-m-d H:i');
    }

    /**
     * @return list<object>
     */
    private static function messagesOf(RecurringMessage $recurringMessage): array
    {
        $context = new MessageContext('default', $recurringMessage->getId(), $recurringMessage->getTrigger(), new \DateTimeImmutable());

        return array_values([...$recurringMessage->getProvider()->getMessages($context)]);
    }
}
