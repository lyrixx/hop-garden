<?php

namespace App\Tests\Integration\Account;

use App\Account\Entity\User;
use App\Account\Model\AccessStatus;
use App\Account\UserRepository;
use App\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "castor app:user:create": the administrator of a new installation, without prompt.
 */
final class CreateUserCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    public function testTheAdministrator(): void
    {
        self::mockTime('2026-11-22 10:00');
        $tester = $this->command();

        $tester->execute(['--email' => 'Admin@Example.com', '--name' => 'Admin', '--password' => 'a long enough password', '--admin' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Admin <admin@example.com> has an account: the administrator, approved.', $tester->getDisplay());
        $admin = $this->user('admin@example.com');
        self::assertSame(['ROLE_USER', User::ROLE_ADMIN], $admin->getRoles());
        self::assertSame('2026-11-22 10:00', $admin->getEmailVerifiedAt()?->format('Y-m-d H:i'), 'Vouched for by whoever runs it.');
        self::assertNull($admin->getPreferredLocale(), 'Their browser decides until they choose.');
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($admin, 'a long enough password'));
    }

    public function testAMemberApprovedOrOnTheWaitingList(): void
    {
        $this->command()->execute(['--email' => 'alice@example.com', '--name' => 'Alice', '--password' => 'a long enough password', '--approved' => true]);
        $this->command()->execute(['--email' => 'bob@example.com', '--name' => 'Bob', '--password' => 'a long enough password']);

        self::assertSame(['ROLE_USER'], $this->user('alice@example.com')->getRoles());
        self::assertSame(AccessStatus::Pending, $this->user('bob@example.com')->getAccessStatus());
        self::assertTrue($this->user('bob@example.com')->isWaiting());
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('provideNothingIsCreatedFromInvalidOptionsCases')]
    public function testNothingIsCreatedFromInvalidOptions(array $options, string $error): void
    {
        UserFactory::createOne(['email' => 'alice@example.com']);
        $tester = $this->command();

        $tester->execute($options + ['--email' => 'bob@example.com', '--name' => 'Bob', '--password' => 'a long enough password']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString($error, preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertCount(1, self::getContainer()->get(UserRepository::class)->findAll());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function provideNothingIsCreatedFromInvalidOptionsCases(): iterable
    {
        yield 'no email' => [['--email' => ''], '--email: This value should not be blank.'];
        yield 'not an email' => [['--email' => 'bob'], '--email: This value is not a valid email address.'];
        yield 'no name' => [['--name' => ' '], '--name: This value should not be blank.'];
        yield 'a short password' => [['--password' => 'short'], '--password: This value is too short.'];
        yield 'an email taken' => [['--email' => 'ALICE@example.com'], 'An account has the email "alice@example.com" already.'];
    }

    private function command(): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find('app:user:create'));
    }

    private function user(string $email): User
    {
        return self::getContainer()->get(UserRepository::class)->findOneByEmail($email) ?? self::fail('No account for ' . $email . '.');
    }
}
