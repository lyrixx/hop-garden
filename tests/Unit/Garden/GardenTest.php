<?php

namespace App\Tests\Unit\Garden;

use App\Account\Entity\User;
use App\Factory\UserFactory;
use App\Garden\Entity\Garden;
use App\Garden\Entity\GardenLocation;
use App\Garden\Model\MembershipRole;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class GardenTest extends TestCase
{
    public function testItAlwaysHasItsOwnerAndTheOthersJoinIt(): void
    {
        [$alice, $bob, $carol] = [UserFactory::createOne(['email' => 'alice@example.com']), UserFactory::createOne(['email' => 'bob@example.com']), UserFactory::createOne(['email' => 'carol@example.com'])];
        $garden = self::garden($alice);

        $garden->join($bob, new \DateTimeImmutable('2026-10-02'));
        $garden->join($carol, new \DateTimeImmutable('2026-10-03'));

        self::assertSame($alice, $garden->getOwner());
        self::assertSame([$alice, $bob, $carol], $garden->getMembers());
        self::assertTrue($garden->isOwnedBy($alice));
        self::assertFalse($garden->isOwnedBy($bob));
        self::assertSame(MembershipRole::Member, $garden->membershipOf($bob)?->getRole());
        self::assertNull($garden->membershipOf(UserFactory::createOne(['email' => 'dave@example.com'])));
    }

    public function testTheOldestOfTheOthersTakesItOverWhenItsOwnerLeaves(): void
    {
        [$alice, $bob, $carol] = [UserFactory::createOne(['email' => 'alice@example.com']), UserFactory::createOne(['email' => 'bob@example.com']), UserFactory::createOne(['email' => 'carol@example.com'])];
        $garden = self::garden($alice);
        $garden->join($bob, new \DateTimeImmutable('2026-10-02'));
        $garden->join($carol, new \DateTimeImmutable('2026-10-03'));

        $garden->leave($alice);

        self::assertSame([$bob, $carol], $garden->getMembers());
        self::assertSame($bob, $garden->getOwner());

        $garden->leave($carol);
        self::assertSame($bob, $garden->getOwner(), 'A member leaves: the owner stays.');
    }

    public function testItsLastMemberDoesNotLeaveIt(): void
    {
        $alice = UserFactory::createOne();
        $garden = self::garden($alice);

        $this->expectException(\LogicException::class);
        $garden->leave($alice);
    }

    public function testItsOwnerRemovesAMemberNeverThemselves(): void
    {
        [$alice, $bob] = [UserFactory::createOne(['email' => 'alice@example.com']), UserFactory::createOne(['email' => 'bob@example.com'])];
        $garden = self::garden($alice);
        $garden->join($bob, new \DateTimeImmutable('2026-10-02'));
        $owner = $garden->membershipOf($alice) ?? self::fail();

        $garden->remove($garden->membershipOf($bob) ?? self::fail());

        self::assertSame([$alice], $garden->getMembers());
        $this->expectException(\LogicException::class);
        $garden->remove($owner);
    }

    public function testAMemberJoinsOnce(): void
    {
        $alice = UserFactory::createOne();
        $garden = self::garden($alice);

        $this->expectException(\LogicException::class);
        $garden->join($alice, new \DateTimeImmutable('2026-10-02'));
    }

    /**
     * Once a day, from 7:00 in its timezone: the server clock is UTC.
     */
    #[TestWith(['Europe/Paris', '2026-12-01 05:59', false])]
    #[TestWith(['Europe/Paris', '2026-12-01 06:00', true])]
    #[TestWith(['America/New_York', '2026-12-01 11:59', false])]
    #[TestWith(['America/New_York', '2026-12-01 12:00', true])]
    #[TestWith(['Asia/Tokyo', '2026-11-30 22:00', true])]
    public function testTheRemindersOfAGardenAreCheckedFrom7InItsTimezone(string $timezone, string $now, bool $due): void
    {
        $garden = self::garden(UserFactory::createOne(), $timezone);
        $now = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));

        self::assertSame($due, $garden->isDueForReminderCheck($now));

        $garden->markRemindersChecked($now);
        self::assertFalse($garden->isDueForReminderCheck($now->modify('+3 hours')), 'Checked for the day.');
        self::assertSame($due, $garden->isDueForReminderCheck($now->modify('+1 day')), 'Due again the day after.');
    }

    public function testItsLocationIsAPlaceOnEarth(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GardenLocation('Nowhere', 91.0, 0.0, 'Europe/Paris');
    }

    public function testItsTimezoneIsOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GardenLocation('Mars', 0.0, 0.0, 'Mars/Olympus');
    }

    private static function garden(User $owner, string $timezone = 'Europe/Paris'): Garden
    {
        return Garden::create('The allotment', new GardenLocation('Somewhere', 45.0, 4.0, $timezone), $owner, new \DateTimeImmutable('2026-10-01'));
    }
}
