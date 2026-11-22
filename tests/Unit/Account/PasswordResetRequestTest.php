<?php

namespace App\Tests\Unit\Account;

use App\Account\Entity\PasswordResetRequest;
use App\Account\Entity\User;
use PHPUnit\Framework\TestCase;

final class PasswordResetRequestTest extends TestCase
{
    public function testItKeepsAHashOfItsTokenForOneHour(): void
    {
        $user = User::register('alice@example.com', 'Alice', 'a-bcrypt-hash', null, new \DateTimeImmutable('2026-11-01'));

        $request = new PasswordResetRequest($user, 'the-token', new \DateTimeImmutable('2026-11-22 10:00'));

        self::assertSame($user, $request->getUser());
        self::assertSame(hash('sha256', 'the-token'), PasswordResetRequest::hash('the-token'));
        self::assertStringNotContainsString('the-token', serialize($request));
        self::assertSame('2026-11-22 11:00', $request->getExpiresAt()->format('Y-m-d H:i'));
        self::assertFalse($request->isExpired(new \DateTimeImmutable('2026-11-22 10:59')));
        self::assertTrue($request->isExpired(new \DateTimeImmutable('2026-11-22 11:00')));
    }
}
