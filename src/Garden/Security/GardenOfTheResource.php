<?php

namespace App\Garden\Security;

use App\Garden\CurrentGarden;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Plant\Entity\Plant;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A member who opens a care sheet, a plant or a space of another of their gardens (a link of the agent of an external
 * client, bound to that garden, or kept from before a switch) switches to that garden first: then the voters, which
 * want the current garden (GardenResourceVoter), let them in, and the page shows a garden as a whole. Before the
 * attributes of the controller (IsGranted). Nothing changes for whoever is no member of it: the same 404.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS)]
final readonly class GardenOfTheResource
{
    public function __construct(
        private CurrentGarden $currentGarden,
        private Security $security,
    ) {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        foreach ($event->getArguments() as $argument) {
            if ($argument instanceof Plant || $argument instanceof Planting || $argument instanceof Space) {
                $this->follow($argument->getGarden());
            }
        }
    }

    /**
     * Only on the website, whose current garden is a choice of the session: a token bound to a garden keeps its own.
     */
    public function follow(Garden $garden): void
    {
        $current = $this->currentGarden->find();
        if (null === $current || $current === $garden || !$this->security->isGranted(GardenVoter::VIEW, $garden)) {
            return;
        }

        $this->currentGarden->switchTo($garden);
    }
}
