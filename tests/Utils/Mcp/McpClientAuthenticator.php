<?php

namespace App\Tests\Utils\Mcp;

use App\Account\UserRepository;
use App\Garden\CurrentGarden;
use App\Mcp\Security\McpEntryPoint;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Uid\Uuid;

/**
 * In the tests only, on the firewall of /_mcp (config/packages/security.yaml, when@test): stands for the OAuth
 * authenticator of phase 3, which will log in the user of an access token, bound to the garden they chose. Its token
 * says it plainly: "Bearer test-client:<user id>:<garden id>", both UUIDs. The binding is the production one
 * (CurrentGarden::TOKEN_ATTRIBUTE), and so are the voters and the tools behind it.
 */
final class McpClientAuthenticator extends AbstractAuthenticator
{
    private const string PREFIX = 'Bearer test-client:';

    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public static function header(Uuid $userId, Uuid $gardenId): string
    {
        return \sprintf('%s%s:%s', self::PREFIX, $userId->toRfc4122(), $gardenId->toRfc4122());
    }

    public function supports(Request $request): bool
    {
        return str_starts_with($request->headers->get('Authorization', ''), self::PREFIX);
    }

    public function authenticate(Request $request): Passport
    {
        [$userId, $gardenId] = explode(':', substr($request->headers->get('Authorization', ''), \strlen(self::PREFIX)), 2) + [1 => ''];
        $user = (Uuid::isValid($userId) ? $this->userRepository->find(Uuid::fromString($userId)) : null) ?? throw new CustomUserMessageAuthenticationException('No such user.');

        $passport = new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));
        $passport->setAttribute(CurrentGarden::TOKEN_ATTRIBUTE, $gardenId);

        return $passport;
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $token = parent::createToken($passport, $firewallName);
        $token->setAttribute(CurrentGarden::TOKEN_ATTRIBUTE, $passport->getAttribute(CurrentGarden::TOKEN_ATTRIBUTE));

        return $token;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return McpEntryPoint::unauthorized($request, $exception->getMessageKey());
    }
}
