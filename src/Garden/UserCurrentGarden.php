<?php

namespace App\Garden;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use App\Garden\Exception\NoCurrentGardenException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A token bound to a garden (CurrentGarden::TOKEN_ATTRIBUTE) gets that one, while its user is a member of it, and never
 * another. A page of the website gets the garden of its URL, which GardenOfTheUrl found and checked before. Nothing
 * else: no garden on a page out of a garden, in the worker, the console or for a visitor.
 */
final class UserCurrentGarden implements CurrentGarden, ResetInterface
{
    /**
     * Read once per bound token: the tools and the voters ask several times a request.
     *
     * @var \WeakMap<TokenInterface, ?Garden>
     */
    private \WeakMap $bound;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly GardenRepository $gardenRepository,
    ) {
        $this->bound = new \WeakMap();
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

        if ($token->hasAttribute(self::TOKEN_ATTRIBUTE)) {
            if (!$this->bound->offsetExists($token)) {
                $this->bound[$token] = $this->boundGarden($token);
            }

            return $this->bound[$token];
        }

        $garden = $this->requestStack->getMainRequest()?->attributes->get(GardenOfTheUrl::ATTRIBUTE);

        return $garden instanceof Garden ? $garden : null;
    }

    public function reset(): void
    {
        $this->bound = new \WeakMap();
    }

    private function boundGarden(TokenInterface $token): ?Garden
    {
        /** @var User $user */
        $user = $token->getUser();
        $gardenId = $token->getAttribute(self::TOKEN_ATTRIBUTE);

        return \is_string($gardenId) && Uuid::isValid($gardenId) ? $this->gardenRepository->findOneOfMember(Uuid::fromString($gardenId), $user) : null;
    }
}
