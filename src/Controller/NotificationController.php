<?php

namespace App\Controller;

use App\Garden\PlantingRepository;
use App\Reminder\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NotificationController extends AbstractController
{
    #[Route('/notifications', name: 'notifications', methods: ['GET'])]
    public function index(NotificationRepository $notificationRepository, PlantingRepository $plantingRepository, EntityManagerInterface $entityManager, ClockInterface $clock): Response
    {
        $notifications = $notificationRepository->findLatest();
        $plants = [];
        foreach ($notifications as $notification) {
            $plant = $notification->getReminder()->getPlant();
            $plants[$plant->getId()] = $plant;
        }

        $response = $this->render('notification/index.html.twig', [
            'notifications' => $notifications,
            // Each one links to my plant of its species, in the garden: see my_plant_path()
            'planting_ids' => $plantingRepository->findIdsForPlants(array_values($plants)),
        ]);

        // Seen once listed: the "unread" highlight is computed before
        foreach ($notificationRepository->findUnread() as $notification) {
            $notification->markRead($clock->now());
        }
        $entityManager->flush();

        return $response;
    }
}
