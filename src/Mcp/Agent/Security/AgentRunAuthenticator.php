<?php

namespace App\Mcp\Agent\Security;

use App\Account\UserRepository;
use App\Garden\CurrentGarden;
use App\Garden\GardenRepository;
use App\Mcp\Agent\AgentRunTokens;
use App\Mcp\Agent\Exception\InvalidAgentRunTokenException;
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

/**
 * The firewall of /_mcp/agent, stateless: each request of the worker carries the token of its run (AgentRunTokens). It
 * logs in whoever sent the message, bound to the garden of the run (CurrentGarden::TOKEN_ATTRIBUTE), while they are
 * still a member of it. A request without token, or with a forged or expired one, gets a 401 (McpEntryPoint).
 */
final class AgentRunAuthenticator extends AbstractAuthenticator
{
    /**
     * The conversation of the run, on its security token: what tells the agent from a human (PlantValidationVoter).
     */
    public const string TOKEN_ATTRIBUTE = 'agent_run_conversation_id';

    public function __construct(
        private readonly AgentRunTokens $agentRunTokens,
        private readonly UserRepository $userRepository,
        private readonly GardenRepository $gardenRepository,
    ) {
    }

    public function supports(Request $request): bool
    {
        return str_starts_with($request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        try {
            $claims = $this->agentRunTokens->verify(substr($request->headers->get('Authorization', ''), \strlen('Bearer ')));
        } catch (InvalidAgentRunTokenException $e) {
            throw new CustomUserMessageAuthenticationException($e->getMessage(), previous: $e);
        }

        // Deleted, or removed from the garden, since the message was sent
        $user = $this->userRepository->find($claims->userId);
        if (null === $user || null === $this->gardenRepository->findOneOfMember($claims->gardenId, $user)) {
            throw new CustomUserMessageAuthenticationException('The sender of the message is no member of its garden any more.');
        }

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user), [new AgentRunBadge($claims)]);
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $token = parent::createToken($passport, $firewallName);
        $claims = $passport->getBadge(AgentRunBadge::class);
        if (!$claims instanceof AgentRunBadge) {
            throw new \LogicException('The passport of an agent run carries its claims.');
        }

        $token->setAttribute(CurrentGarden::TOKEN_ATTRIBUTE, $claims->claims->gardenId);
        $token->setAttribute(self::TOKEN_ATTRIBUTE, $claims->claims->conversationId);

        return $token;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return McpEntryPoint::unauthorized($request, strtr($exception->getMessageKey(), $exception->getMessageData()));
    }
}
