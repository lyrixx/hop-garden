<?php

namespace App\Tests\Unit\Account;

use App\Account\Entity\User;
use App\Account\Model\AccessStatus;
use App\Locale\Model\SupportedLocale;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    private const string REGISTERED_AT = '2026-11-01 10:00';

    public function testARegistrationWaitsForItsEmail(): void
    {
        $user = self::register(' Alice@Example.COM ');

        self::assertSame('alice@example.com', $user->getEmail(), 'Found whatever its case.');
        self::assertSame('alice@example.com', $user->getUserIdentifier());
        self::assertSame('Alice', $user->getDisplayName());
        self::assertSame(SupportedLocale::French, $user->getPreferredLocale());
        self::assertSame(self::REGISTERED_AT, $user->getCreatedAt()->format('Y-m-d H:i'));
        self::assertSame(AccessStatus::Pending, $user->getAccessStatus());
        self::assertFalse($user->isEmailVerified());
        self::assertFalse($user->isWaiting(), 'Not on the list before its email is verified.');
        self::assertSame([], $user->getRoles(), 'No ROLE_USER: the garden stays closed.');
    }

    public function testItsEmailIsVerifiedOnce(): void
    {
        $user = self::register();

        $user->verifyEmail(new \DateTimeImmutable('2026-11-01 10:10'));
        $user->verifyEmail(new \DateTimeImmutable('2026-11-02 08:00'));

        self::assertTrue($user->isWaiting());
        self::assertSame('2026-11-01 10:10', $user->getEmailVerifiedAt()?->format('Y-m-d H:i'), 'The first time.');
    }

    public function testApprovedItGetsIn(): void
    {
        $user = self::waiting();

        $user->approve(new \DateTimeImmutable('2026-11-02 09:00'));

        self::assertSame(AccessStatus::Approved, $user->getAccessStatus());
        self::assertSame('2026-11-02 09:00', $user->getApprovedAt()?->format('Y-m-d H:i'));
        self::assertFalse($user->isWaiting());
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertFalse($user->isAdmin());
    }

    public function testRefusedItStaysOut(): void
    {
        $user = self::waiting();

        $user->refuse();

        self::assertSame(AccessStatus::Refused, $user->getAccessStatus());
        self::assertNull($user->getApprovedAt());
        self::assertSame([], $user->getRoles());
    }

    /**
     * Its email unverified yet: App\Account\Security\UserChecker keeps it out until it is.
     */
    public function testAnInvitationLetsANewAccountInWithoutTheWaitingList(): void
    {
        $user = self::register();

        $user->admitByInvitation(new \DateTimeImmutable('2026-11-01 10:00'));

        self::assertSame(AccessStatus::Approved, $user->getAccessStatus());
        self::assertSame('2026-11-01 10:00', $user->getApprovedAt()?->format('Y-m-d H:i'));
        self::assertFalse($user->isEmailVerified());
        self::assertFalse($user->isWaiting());

        $refused = self::waiting();
        $refused->refuse();
        $this->expectException(\LogicException::class);
        $refused->admitByInvitation(new \DateTimeImmutable('2026-11-02'));
    }

    public function testOnlyAnAccountOnTheWaitingListGetsADecision(): void
    {
        $unverified = self::register();
        $approved = self::waiting();
        $approved->approve(new \DateTimeImmutable('2026-11-02'));

        foreach ([[$unverified, 'with an unverified email'], [$approved, 'approved']] as [$user, $state]) {
            try {
                $user->refuse();
                self::fail('Refused an account ' . $state . '.');
            } catch (\LogicException $e) {
                self::assertStringContainsString($state, $e->getMessage());
            }
        }

        $this->expectException(\LogicException::class);
        $approved->approve(new \DateTimeImmutable('2026-11-03'));
    }

    public function testTheAdministratorHasTheBackOfficeOnceApproved(): void
    {
        $user = self::waiting();
        $user->grantAdmin();
        $user->grantAdmin();

        self::assertTrue($user->isAdmin());
        self::assertSame([], $user->getRoles(), 'Approved first, like everyone.');

        $user->approve(new \DateTimeImmutable('2026-11-02'));

        self::assertSame(['ROLE_USER', User::ROLE_ADMIN], $user->getRoles());
    }

    public function testAPasswordResetProvesTheEmailToo(): void
    {
        $user = self::register();

        $user->resetPassword('new-hash', new \DateTimeImmutable('2026-11-03 12:00'));

        self::assertSame('new-hash', $user->getPassword());
        self::assertSame('2026-11-03 12:00', $user->getEmailVerifiedAt()?->format('Y-m-d H:i'));
        self::assertTrue($user->isWaiting());
    }

    public function testANewEmailIsVerifiedByItsLink(): void
    {
        $user = self::waiting();

        $user->changeEmail('Alice.Smith@Example.com', new \DateTimeImmutable('2026-11-05 18:00'));

        self::assertSame('alice.smith@example.com', $user->getEmail());
        self::assertSame('2026-11-05 18:00', $user->getEmailVerifiedAt()?->format('Y-m-d H:i'));
    }

    public function testItsNameItsPasswordAndItsLanguage(): void
    {
        $user = self::register();

        $user->rename('Alice Smith');
        $user->changePassword('another-hash');
        $user->choosePreferredLocale(SupportedLocale::English);

        self::assertSame('Alice Smith', $user->getDisplayName());
        self::assertSame('another-hash', $user->getPassword());
        self::assertSame(SupportedLocale::English, $user->getPreferredLocale());
    }

    public function testTheSessionKeepsAChecksumOfThePasswordHash(): void
    {
        $user = self::register();

        $serialized = serialize($user);

        self::assertStringNotContainsString('a-bcrypt-hash', $serialized);
        self::assertStringContainsString(hash('crc32c', 'a-bcrypt-hash'), $serialized);
    }

    private static function register(string $email = 'alice@example.com'): User
    {
        return User::register($email, 'Alice', 'a-bcrypt-hash', SupportedLocale::French, new \DateTimeImmutable(self::REGISTERED_AT));
    }

    private static function waiting(): User
    {
        $user = self::register();
        $user->verifyEmail(new \DateTimeImmutable('2026-11-01 10:10'));

        return $user;
    }
}
