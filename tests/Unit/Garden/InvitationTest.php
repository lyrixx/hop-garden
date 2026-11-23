<?php

namespace App\Tests\Unit\Garden;

use App\Account\Model\AccessStatus;
use App\Factory\UserFactory;
use App\Garden\Entity\Garden;
use App\Garden\Entity\GardenLocation;
use App\Garden\Entity\Invitation;
use App\Garden\Model\InvitationStatus;
use App\Garden\Model\MembershipRole;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class InvitationTest extends TestCase
{
    public function testItKeepsAHashOfItsTokenForFourteenDays(): void
    {
        $garden = self::garden();

        $invitation = Invitation::send($garden, ' Erin@Example.com ', $garden->getOwner(), 'the-token', new \DateTimeImmutable('2026-11-01 10:00'));

        self::assertInstanceOf(UuidV7::class, $invitation->getId());
        self::assertSame('erin@example.com', $invitation->getEmail(), 'Found whatever the case it is typed in.');
        self::assertSame($garden->getOwner(), $invitation->getInvitedBy());
        self::assertSame(hash('sha256', 'the-token'), Invitation::hash('the-token'));
        self::assertStringNotContainsString('the-token', serialize($invitation));
        self::assertSame('2026-11-15 10:00', $invitation->getExpiresAt()->format('Y-m-d H:i'));
        self::assertSame(InvitationStatus::Pending, $invitation->statusAt(new \DateTimeImmutable('2026-11-15 09:59')));
        self::assertSame(InvitationStatus::Expired, $invitation->statusAt(new \DateTimeImmutable('2026-11-15 10:00')));
        self::assertTrue($invitation->isOpen(), 'Expired, it gets a new link.');
    }

    public function testOnlyAMemberInvites(): void
    {
        $this->expectException(\LogicException::class);

        Invitation::send(self::garden(), 'erin@example.com', UserFactory::createOne(), 'the-token', new \DateTimeImmutable('2026-11-01'));
    }

    public function testItLetsOnePersonInOnceWhateverTheAddressOfTheirAccount(): void
    {
        $garden = self::garden();
        $invitation = Invitation::send($garden, 'erin@example.com', $garden->getOwner(), 'the-token', new \DateTimeImmutable('2026-11-01'));
        $frank = UserFactory::createOne(['email' => 'frank@example.com']);

        $invitation->accept($frank, new \DateTimeImmutable('2026-11-02'));

        self::assertSame(MembershipRole::Member, $garden->membershipOf($frank)?->getRole());
        self::assertSame('2026-11-02', $invitation->getAcceptedAt()?->format('Y-m-d'));
        self::assertSame(InvitationStatus::Accepted, $invitation->statusAt(new \DateTimeImmutable('2026-11-02')));
        self::assertFalse($invitation->isOpen());
        self::assertFalse($invitation->isSentTo($frank));

        $this->expectException(\LogicException::class);
        $invitation->accept(UserFactory::createOne(), new \DateTimeImmutable('2026-11-03'));
    }

    /**
     * As a new account: the address invited is verified by the link, another one is not.
     */
    public function testItLetsAnAccountOfTheWaitingListIn(): void
    {
        $garden = self::garden();
        $erin = UserFactory::createOne(['email' => 'erin@example.com']);
        $frank = UserFactory::new()->verified(new \DateTimeImmutable('2026-10-15'))->create(['email' => 'frank@example.com']);
        $george = UserFactory::createOne(['email' => 'george@example.com']);

        Invitation::send($garden, 'erin@example.com', $garden->getOwner(), 'the-token', new \DateTimeImmutable('2026-11-01'))->accept($erin, new \DateTimeImmutable('2026-11-02'));
        Invitation::send($garden, 'f@example.com', $garden->getOwner(), 'the-token', new \DateTimeImmutable('2026-11-01'))->accept($frank, new \DateTimeImmutable('2026-11-02'));
        Invitation::send($garden, 'g@example.com', $garden->getOwner(), 'the-token', new \DateTimeImmutable('2026-11-01'))->accept($george, new \DateTimeImmutable('2026-11-02'));

        self::assertSame([AccessStatus::Approved, AccessStatus::Approved, AccessStatus::Approved], [$erin->getAccessStatus(), $frank->getAccessStatus(), $george->getAccessStatus()]);
        self::assertSame('2026-11-02', $erin->getEmailVerifiedAt()?->format('Y-m-d'), 'The link came to her address.');
        self::assertSame('2026-10-15', $frank->getEmailVerifiedAt()?->format('Y-m-d'));
        self::assertNull($george->getEmailVerifiedAt(), 'He verifies it first.');
    }

    public function testItIsSentToTheAccountOfItsAddress(): void
    {
        $garden = self::garden();
        $invitation = Invitation::send($garden, 'Erin@example.com', $garden->getOwner(), 'the-token', new \DateTimeImmutable('2026-11-01'));

        self::assertTrue($invitation->isSentTo(UserFactory::createOne(['email' => 'ERIN@example.com'])));
        self::assertFalse($invitation->isSentTo(UserFactory::createOne(['email' => 'erin@example.org'])));
    }

    public function testExpiredItLetsNobodyIn(): void
    {
        $garden = self::garden();
        $invitation = Invitation::send($garden, 'erin@example.com', $garden->getOwner(), 'the-token', new \DateTimeImmutable('2026-11-01'));

        $this->expectException(\LogicException::class);
        $invitation->accept(UserFactory::createOne(), new \DateTimeImmutable('2026-11-16'));
    }

    public function testRevokedItLetsNobodyInAndGetsNoNewLink(): void
    {
        $garden = self::garden();
        $invitation = Invitation::send($garden, 'erin@example.com', $garden->getOwner(), 'the-token', new \DateTimeImmutable('2026-11-01'));

        $invitation->revoke(new \DateTimeImmutable('2026-11-02'));

        self::assertSame(InvitationStatus::Revoked, $invitation->statusAt(new \DateTimeImmutable('2026-11-02')));
        self::assertSame('2026-11-02', $invitation->getRevokedAt()?->format('Y-m-d'));
        self::assertFalse($invitation->isOpen());
        foreach ([
            static fn () => $invitation->accept(UserFactory::createOne(), new \DateTimeImmutable('2026-11-03')),
            static fn () => $invitation->renew('another-token', new \DateTimeImmutable('2026-11-03')),
            static fn () => $invitation->revoke(new \DateTimeImmutable('2026-11-03')),
        ] as $change) {
            try {
                $change();
                self::fail('A revoked invitation does not change.');
            } catch (\LogicException) {
            }
        }
        self::assertCount(1, $garden->getMembers(), 'Its owner only.');
    }

    public function testANewLinkReplacesTheOldOneForFourteenDaysAgain(): void
    {
        $garden = self::garden();
        $invitation = Invitation::send($garden, 'erin@example.com', $garden->getOwner(), 'the-token', new \DateTimeImmutable('2026-11-01 10:00'));
        $invitation->renew('a-new-token', new \DateTimeImmutable('2026-11-20 08:00'));

        self::assertStringContainsString(Invitation::hash('a-new-token'), serialize($invitation));
        self::assertStringNotContainsString(Invitation::hash('the-token'), serialize($invitation), 'The old link no longer finds it.');
        self::assertSame('2026-12-04 08:00', $invitation->getExpiresAt()->format('Y-m-d H:i'));
        self::assertTrue($invitation->isPending(new \DateTimeImmutable('2026-11-20 08:00')), 'Expired, then pending again.');
        self::assertSame('2026-11-01 10:00', $invitation->getCreatedAt()->format('Y-m-d H:i'));
    }

    private static function garden(): Garden
    {
        return Garden::create('The allotment', 'the-allotment', new GardenLocation('Somewhere', 45.0, 4.0, 'Europe/Paris'), UserFactory::createOne(['email' => 'alice@example.com']), new \DateTimeImmutable('2026-10-01'));
    }
}
