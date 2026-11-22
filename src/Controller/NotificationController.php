<?php

namespace App\Controller;

use App\Account\Entity\User;
use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\OpenMeteo\Model\FrostForecast;
use App\DataSource\OpenMeteo\OpenMeteoClient;
use App\Garden\CurrentGarden;
use App\Garden\Entity\Garden;
use App\Garden\PlantingRepository;
use App\Plant\Entity\Plant;
use App\Plant\PlantRepository;
use App\Reminder\CalendarSchedule;
use App\Reminder\FrostRiskEvaluator;
use App\Reminder\Model\UpcomingFrost;
use App\Reminder\Model\UpcomingReminder;
use App\Reminder\NotificationRepository;
use App\Reminder\ReminderChecker;
use App\Reminder\ReminderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The reminders and alerts of the current garden by hand, what the agent gets from upcoming_reminders and
 * get_frost_forecast: the reminders coming up, the frost forecast at the garden with the plants it threatens, then the
 * notifications the member received there.
 */
final class NotificationController extends AbstractController
{
    /**
     * 12 weeks: from late November, the pruning of February shows up.
     */
    private const int COMING_UP_DAYS = 84;

    public function __construct(
        private readonly CalendarSchedule $calendarSchedule,
        private readonly ClockInterface $clock,
        private readonly CurrentGarden $currentGarden,
        private readonly EntityManagerInterface $entityManager,
        private readonly FrostRiskEvaluator $frostRiskEvaluator,
        private readonly NotificationRepository $notificationRepository,
        private readonly OpenMeteoClient $openMeteoClient,
        private readonly PlantingRepository $plantingRepository,
        private readonly PlantRepository $plantRepository,
        private readonly ReminderRepository $reminderRepository,
    ) {
    }

    #[Route('/notifications', name: 'notifications', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        $garden = $this->currentGarden->get();
        $forecast = $this->frostForecast($garden);
        $reminders = $this->reminderRepository->findEnabled($garden);

        $comingUp = [
            ...$this->calendarSchedule->upcoming($reminders, $this->clock->now(), self::COMING_UP_DAYS),
            ...(null !== $forecast ? $this->frostRiskEvaluator->upcoming($reminders, $forecast) : []),
        ];
        // By day: a forecast day starts at midnight in the timezone of the garden, a calendar one in the one of the clock
        usort($comingUp, static fn (UpcomingReminder|UpcomingFrost $a, UpcomingReminder|UpcomingFrost $b): int => $a->startsOn->format('Y-m-d') <=> $b->startsOn->format('Y-m-d'));

        $forecastDays = null !== $forecast ? $this->frostRiskEvaluator->plantsAtRiskByDay($this->plantRepository->findValidated($garden), $forecast) : null;
        $notifications = $this->notificationRepository->findLatest($user, $garden);

        $plants = [];
        foreach ($comingUp as $upcoming) {
            $plants[] = $upcoming->reminder->getPlant();
        }
        foreach ($forecastDays ?? [] as $day) {
            array_push($plants, ...$day->plantsAtRisk);
        }
        foreach ($notifications as $notification) {
            $plants[] = $notification->getReminder()->getPlant();
        }

        $response = $this->render('notification/index.html.twig', [
            'coming_up' => $comingUp,
            'coming_up_days' => self::COMING_UP_DAYS,
            'forecast_days' => $forecastDays,
            'notifications' => $notifications,
            // Each plant links to my plant of its species, in the garden: see my_plant_path()
            'planting_ids' => $this->plantingRepository->findIdsForPlants($garden, self::distinct($plants)),
        ]);

        // Seen once listed: the "unread" highlight is computed before
        foreach ($this->notificationRepository->findUnread($user, $garden) as $notification) {
            $notification->markRead($this->clock->now());
        }
        $this->entityManager->flush();

        return $response;
    }

    private function frostForecast(Garden $garden): ?FrostForecast
    {
        try {
            // The days and the cache of the reminder check and of the page of my plant
            return $this->openMeteoClient->getFrostForecast($garden->getLocation(), ReminderChecker::FROST_FORECAST_DAYS);
        } catch (DataSourceUnavailableException) {
            // Already logged by the client: the page says the forecast is unavailable
            return null;
        }
    }

    /**
     * @param list<Plant> $plants
     *
     * @return list<Plant>
     */
    private static function distinct(array $plants): array
    {
        $distinct = [];
        foreach ($plants as $plant) {
            $distinct[$plant->getId()->toRfc4122()] = $plant;
        }

        return array_values($distinct);
    }
}
