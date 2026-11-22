<?php

namespace App\Tests\Unit\Account;

use App\Account\Entity\User;
use App\Account\Security\UserChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

final class UserCheckerTest extends TestCase
{
    /**
     * @param \Closure(User): void $state
     */
    #[DataProvider('provideOnlyAnApprovedMemberGetsInCases')]
    public function testOnlyAnApprovedMemberGetsIn(\Closure $state, ?string $message): void
    {
        $user = User::register('alice@example.com', 'Alice', 'a-bcrypt-hash', null, new \DateTimeImmutable('2026-11-01'));
        $state($user);
        $checker = new UserChecker();

        $checker->checkPreAuth($user);

        try {
            $checker->checkPostAuth($user);
            self::assertNull($message, 'Let in.');
        } catch (CustomUserMessageAccountStatusException $e) {
            self::assertSame($message, $e->getMessageKey(), 'In translations/security.*.yaml.');
        }
    }

    /**
     * @return iterable<string, array{\Closure(User): void, ?string}>
     */
    public static function provideOnlyAnApprovedMemberGetsInCases(): iterable
    {
        $verified = static fn (User $user) => $user->verifyEmail(new \DateTimeImmutable('2026-11-01'));

        yield 'its email not verified' => [static fn (User $user) => null, 'login.unverified'];
        yield 'on the waiting list' => [$verified, 'login.pending'];
        yield 'refused' => [static function (User $user) use ($verified): void {
            $verified($user);
            $user->refuse();
        }, 'login.refused'];
        yield 'approved' => [static function (User $user) use ($verified): void {
            $verified($user);
            $user->approve(new \DateTimeImmutable('2026-11-02'));
        }, null];
    }
}
