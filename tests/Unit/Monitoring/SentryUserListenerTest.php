<?php

namespace App\Tests\Unit\Monitoring;

use App\Account\Entity\User;
use App\Factory\UserFactory;
use App\Monitoring\SentryUserListener;
use PHPUnit\Framework\TestCase;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

final class SentryUserListenerTest extends TestCase
{
    public function testIdentifiesAMemberByIdOnly(): void
    {
        $user = UserFactory::new()->approved()->create(['email' => 'alice@example.com', 'displayName' => 'Alice']);

        $sentryUser = $this->handleRequest($user, HttpKernelInterface::MAIN_REQUEST);

        self::assertNotNull($sentryUser);
        self::assertSame('user:' . $user->getId()->toRfc4122(), $sentryUser->getId());
        self::assertNull($sentryUser->getEmail());
        self::assertNull($sentryUser->getUsername());
        self::assertNull($sentryUser->getIpAddress());
        self::assertSame([], $sentryUser->getMetadata());
    }

    public function testIgnoresAnonymousVisitors(): void
    {
        self::assertNull($this->handleRequest(null, HttpKernelInterface::MAIN_REQUEST));
    }

    public function testIgnoresAnotherKindOfUser(): void
    {
        self::assertNull($this->handleRequest(new InMemoryUser('alice@example.com', null, ['ROLE_USER']), HttpKernelInterface::MAIN_REQUEST));
    }

    public function testIgnoresSubRequests(): void
    {
        self::assertNull($this->handleRequest(UserFactory::new()->approved()->create(), HttpKernelInterface::SUB_REQUEST));
    }

    public function testIgnoresStatelessRoutes(): void
    {
        $request = new Request(attributes: ['_stateless' => true]);

        self::assertNull($this->handleRequest(UserFactory::new()->approved()->create(), HttpKernelInterface::MAIN_REQUEST, $request));
    }

    private function handleRequest(?UserInterface $user, int $requestType, Request $request = new Request()): ?UserDataBag
    {
        $hub = new Hub();
        $tokenStorage = new TokenStorage();
        if (null !== $user) {
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user instanceof User ? $user->getRoles() : []));
        }

        $event = new RequestEvent(self::createStub(HttpKernelInterface::class), $request, $requestType);
        new SentryUserListener($hub, $tokenStorage)($event);

        return $this->getScopeUser($hub);
    }

    private function getScopeUser(HubInterface $hub): ?UserDataBag
    {
        $user = null;
        $hub->configureScope(static function (Scope $scope) use (&$user): void {
            $user = $scope->getUser();
        });

        return $user;
    }
}
