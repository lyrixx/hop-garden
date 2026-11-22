<?php

namespace App\Mercure;

use App\Account\Entity\User;
use App\Garden\GardenRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;

/**
 * The subscriber cookie of the Mercure hub: the topics of a member, and those of each of their gardens
 * (Topics::subscriberGrants()): one cookie for every tab, whatever its garden. Set by every page of a member, and
 * renewed by a page left open (MercureController).
 */
final readonly class SubscriberCookie
{
    public function __construct(
        private Authorization $authorization,
        private GardenRepository $gardenRepository,
        private Topics $topics,
    ) {
    }

    public function set(Request $request, User $member): void
    {
        $this->authorization->setCookie($request, $this->topics->subscriberGrants($member, $this->gardenRepository->findOfMember($member)));
    }
}
