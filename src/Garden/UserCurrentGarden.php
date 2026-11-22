<?php

namespace App\Garden;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use App\Garden\Exception\NoCurrentGardenException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A token bound to a garden (CurrentGarden::TOKEN_ATTRIBUTE) gets that one, while its user is a member of it, and never
 * another. A member logged in on the website gets the one of their session, else the one they joined first. Nothing
 * else: no garden in the worker, the console or for a visitor.
 */
final class UserCurrentGarden implements CurrentGarden, ResetInterface
{
    private const string SESSION_KEY = 'current_garden_id';

    /**
     * Read once per security token: the templates and the voters ask several times a request.
     *
     * @var \WeakMap<TokenInterface, ?Garden>
     */
    private \WeakMap $found;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly GardenRepository $gardenRepository,
    ) {
        $this->found = new \WeakMap();
    }

    public function get(): Garden
    {
        return $this->find() ?? throw NoCurrentGardenException::create();
    }

    public function find(): ?Garden
    {
        $token = $this->security->getToken();
        if (null === $token || !$token->getUser() instanceof User) {
            return null;
        }

        if (!$this->found->offsetExists($token)) {
            $this->found[$token] = $this->lookUp($token);
        }

        return $this->found[$token];
    }

    public function switchTo(Garden $garden): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, $garden->getId());
        $this->found = new \WeakMap();
    }

    public function reset(): void
    {
        $this->found = new \WeakMap();
    }

    private function lookUp(TokenInterface $token): ?Garden
    {
        /** @var User $user */
        $user = $token->getUser();

        if ($token->hasAttribute(self::TOKEN_ATTRIBUTE)) {
            $gardenId = $token->getAttribute(self::TOKEN_ATTRIBUTE);

            return \is_int($gardenId) ? $this->gardenRepository->findOneOfMember($gardenId, $user) : null;
        }

        // The website only: a stateless firewall gets its garden from its token, or none
        $request = $this->requestStack->getMainRequest();
        if (null === $request || !$request->hasSession() || false !== $this->security->getFirewallConfig($request)?->isStateless()) {
            return null;
        }

        $gardenId = $request->getSession()->get(self::SESSION_KEY);
        $garden = \is_int($gardenId) ? $this->gardenRepository->findOneOfMember($gardenId, $user) : null;

        // Left since, or never chosen
        return $garden ?? $this->gardenRepository->findOfMember($user)[0] ?? null;
    }
}
