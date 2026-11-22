<?php

namespace App\Garden;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * The last garden a member was in, for what is out of a garden: the navigation of the account or of the wizard, and the
 * links of before the gardens were in the URLs (/assistant, /garden...: LastGardenController). Remembered by the
 * session (GardenOfTheUrl), else the one they joined first. Never the current garden of a request: CurrentGarden.
 */
final readonly class LastGarden
{
    private const string SESSION_KEY = 'last_garden_id';

    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private GardenRepository $gardenRepository,
    ) {
    }

    /**
     * Null for a visitor, a member without garden yet, or a stateless request.
     */
    public function find(): ?Garden
    {
        $user = $this->security->getUser();
        $request = $this->requestStack->getMainRequest();
        if (!$user instanceof User || null === $request || !$request->hasSession() || false !== $this->security->getFirewallConfig($request)?->isStateless()) {
            return null;
        }

        $gardenId = $request->getSession()->get(self::SESSION_KEY);
        // Left since, or deleted
        $garden = \is_string($gardenId) && Uuid::isValid($gardenId) ? $this->gardenRepository->findOneOfMember(Uuid::fromString($gardenId), $user) : null;

        return $garden ?? $this->gardenRepository->findOfMember($user)[0] ?? null;
    }

    public function remember(Garden $garden): void
    {
        $session = $this->requestStack->getSession();
        if ($session->get(self::SESSION_KEY) !== $garden->getId()->toRfc4122()) {
            $session->set(self::SESSION_KEY, $garden->getId()->toRfc4122());
        }
    }
}
