<?php

namespace App\Tests\Integration\Garden;

use App\Factory\GardenFactory;
use App\Factory\InvitationFactory;
use App\Factory\UserFactory;
use App\Garden\Entity\Invitation;
use App\Garden\Invitations;
use App\Locale\Model\SupportedLocale;
use App\Reminder\NotificationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class InvitationsTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    /**
     * Who invited left the garden: its owner is told, in their language.
     */
    public function testTheOwnerIsToldWhenWhoInvitedLeftIt(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-02 10:00');
        $alice = UserFactory::new()->approved()->create(['displayName' => 'Alice', 'preferredLocale' => SupportedLocale::English]);
        $bob = UserFactory::new()->approved()->create(['displayName' => 'Bob', 'preferredLocale' => SupportedLocale::French]);
        $garden = GardenFactory::new()->ownedBy($alice)->withMembers($bob)->create(['name' => 'Le potager']);
        $invitation = InvitationFactory::createOne(['garden' => $garden, 'email' => 'frank@example.com']);
        $garden->leave($alice);
        $frank = UserFactory::new()->approved()->create(['email' => 'frank@example.com', 'displayName' => 'Frank']);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        self::assertTrue($entityManager->wrapInTransaction(static fn (): bool => self::getContainer()->get(Invitations::class)->accept($invitation, $frank)));

        self::assertSame(['Frank a rejoint Le potager.'], array_map(static fn ($notification): string => $notification->getMessage(), self::getContainer()->get(NotificationRepository::class)->findLatest($bob, $garden)));
        $dan = UserFactory::createOne();
        self::assertFalse($entityManager->wrapInTransaction(static fn (): bool => self::getContainer()->get(Invitations::class)->accept($invitation, $dan)), 'Accepted: nobody else.');
    }

    /**
     * Two at once, by a double click: the database refuses the second one.
     */
    public function testTheDatabaseKeepsOneOpenInvitationPerAddressInAGarden(): void
    {
        self::bootKernel();
        $garden = GardenFactory::createOne();
        InvitationFactory::new()->accepted(UserFactory::new()->approved()->create())->create(['garden' => $garden, 'email' => 'erin@example.com']);
        InvitationFactory::new()->revoked()->create(['garden' => $garden, 'email' => 'erin@example.com']);
        InvitationFactory::createOne(['garden' => $garden, 'email' => 'erin@example.com']);
        InvitationFactory::createOne(['garden' => GardenFactory::createOne(), 'email' => 'erin@example.com']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(Invitation::send($garden, 'Erin@example.com', $garden->getOwner(), 'another-token', new \DateTimeImmutable('2026-11-02')));
        $this->expectException(UniqueConstraintViolationException::class);
        $entityManager->flush();
    }
}
