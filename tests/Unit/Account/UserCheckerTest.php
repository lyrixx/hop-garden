<?php

namespace App\Tests\Unit\Account;

use App\Account\Entity\User;
use App\Account\Security\UserChecker;
use App\Garden\Entity\Garden;
use App\Garden\Entity\GardenLocation;
use App\Garden\Entity\Invitation;
use App\Garden\Security\InvitationAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

final class UserCheckerTest extends TestCase
{
    private const string NOW = '2026-11-10 10:00';

    /**
     * @param \Closure(User): mixed $state
     */
    #[DataProvider('provideOnlyAnApprovedMemberGetsInCases')]
    public function testOnlyAnApprovedMemberGetsIn(\Closure $state, ?string $message): void
    {
        $user = self::user('alice@example.com');
        $state($user);

        self::assertSame($message, self::check($user, new Request()));
    }

    /**
     * @return iterable<string, array{\Closure(User): mixed, ?string}>
     */
    public static function provideOnlyAnApprovedMemberGetsInCases(): iterable
    {
        yield 'its email not verified' => [static fn (User $user) => null, 'login.unverified'];
        yield 'on the waiting list' => [self::verified(...), 'login.pending'];
        yield 'refused' => [self::refused(...), 'login.refused'];
        yield 'approved' => [self::approved(...), null];
    }

    /**
     * The login of the link of a pending invitation (InvitationAuthenticator) lets in the waiting list, and the address it
     * was sent to, as a new account: never a refused account, nor another address not verified.
     *
     * @param \Closure(User): mixed $state
     */
    #[DataProvider('provideThePendingInvitationOfTheLoginLetsInTheWaitingListCases')]
    public function testThePendingInvitationOfTheLoginLetsInTheWaitingList(string $email, \Closure $state, ?string $message): void
    {
        $user = self::user($email);
        $state($user);
        $request = new Request();
        $request->attributes->set(InvitationAuthenticator::INVITATION, self::invitation('2026-11-01 10:00'));

        self::assertSame($message, self::check($user, $request));
    }

    /**
     * @return iterable<string, array{string, \Closure(User): mixed, ?string}>
     */
    public static function provideThePendingInvitationOfTheLoginLetsInTheWaitingListCases(): iterable
    {
        yield 'on the waiting list' => ['frank@example.com', self::verified(...), null];
        yield 'its email not verified, the one invited' => ['erin@example.com', static fn (User $user) => null, null];
        yield 'its email not verified, another one' => ['frank@example.com', static fn (User $user) => null, 'login.unverified'];
        yield 'refused' => ['erin@example.com', self::refused(...), 'login.refused'];
        yield 'approved' => ['frank@example.com', self::approved(...), null];
    }

    /**
     * @param \Closure(Invitation): mixed $makeItUnavailable
     */
    #[DataProvider('provideAnInvitationThatNoLongerWorksLetsNobodyInCases')]
    public function testAnInvitationThatNoLongerWorksLetsNobodyIn(string $sentAt, \Closure $makeItUnavailable): void
    {
        $invitation = self::invitation($sentAt);
        $makeItUnavailable($invitation);
        $request = new Request();
        $request->attributes->set(InvitationAuthenticator::INVITATION, $invitation);

        self::assertSame('login.pending', self::check(self::verified(self::user('erin@example.com')), $request));
    }

    /**
     * @return iterable<string, array{string, \Closure(Invitation): mixed}>
     */
    public static function provideAnInvitationThatNoLongerWorksLetsNobodyInCases(): iterable
    {
        yield 'expired' => ['2026-10-20 10:00', static fn (Invitation $invitation) => null];
        yield 'revoked' => ['2026-11-01 10:00', static fn (Invitation $invitation) => $invitation->revoke(new \DateTimeImmutable('2026-11-02'))];
        yield 'accepted' => ['2026-11-01 10:00', static fn (Invitation $invitation) => $invitation->accept(self::approved(self::user('dan@example.com')), new \DateTimeImmutable('2026-11-02'))];
    }

    public function testWhatAClientSendsIsNoInvitation(): void
    {
        $request = new Request(['_invitation' => 'the-token', 'token' => 'the-token'], ['_invitation' => 'the-token', 'token' => 'the-token']);

        self::assertSame('login.pending', self::check(self::verified(self::user('erin@example.com')), $request), 'Only InvitationAuthenticator sets it, on the request attributes.');
    }

    private static function check(User $user, Request $request): ?string
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $checker = new UserChecker($requestStack, new MockClock(self::NOW));

        $checker->checkPreAuth($user);

        try {
            $checker->checkPostAuth($user);

            return null;
        } catch (CustomUserMessageAccountStatusException $e) {
            // In translations/security.*.yaml
            return $e->getMessageKey();
        }
    }

    private static function user(string $email): User
    {
        return User::register($email, 'Somebody', 'a-bcrypt-hash', null, new \DateTimeImmutable('2026-11-01'));
    }

    private static function verified(User $user): User
    {
        $user->verifyEmail(new \DateTimeImmutable('2026-11-01'));

        return $user;
    }

    private static function approved(User $user): User
    {
        self::verified($user)->approve(new \DateTimeImmutable('2026-11-02'));

        return $user;
    }

    private static function refused(User $user): User
    {
        self::verified($user)->refuse();

        return $user;
    }

    /**
     * To erin@example.com, for 14 days.
     */
    private static function invitation(string $sentAt): Invitation
    {
        $alice = self::approved(self::user('alice@example.com'));
        $garden = Garden::create('The allotment', 'the-allotment', new GardenLocation('Somewhere', 45.0, 4.0, 'Europe/Paris'), $alice, new \DateTimeImmutable('2026-10-01'));

        return Invitation::send($garden, 'erin@example.com', $alice, 'the-token', new \DateTimeImmutable($sentAt));
    }
}
