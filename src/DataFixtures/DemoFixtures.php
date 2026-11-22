<?php

namespace App\DataFixtures;

use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Garden\Model\CareTask;
use App\Garden\Model\Soil;
use App\Plant\Entity\CareSheet;
use App\Plant\Entity\Plant;
use App\Plant\Form\Model\CareSheetFormData;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\Confidence;
use App\Plant\Model\Exposure;
use App\Plant\Model\Source;
use App\Plant\Model\Watering;
use App\Reminder\Model\ReminderType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;

/**
 * "castor demo:reset": two validated plants with their reminders, their plantings in three spaces of the garden, no
 * conversation, no notification.
 */
final class DemoFixtures extends Fixture
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        $rose = Plant::propose(
            'Climbing rose',
            'Rosa canina',
            'Rosaceae',
            new CareSheet(
                -20,
                false,
                Exposure::Sun,
                Watering::Medium,
                [2, 3],
                [6, 7, 8, 9],
                [9, 10],
                'Cut out dead wood, then shorten the side shoots to 2 or 3 buds.',
                'Take hardwood cuttings of 20 cm in autumn and plant them deep in a sheltered spot.',
                self::confidence(Confidence::High),
                [new Source('Trefle'), new Source(Source::LLM_KNOWLEDGE)],
            ),
            $now->modify('-30 days'),
            trefleId: 263311,
        );
        $rose->validate($now->modify('-30 days'));
        $rose->enableReminder(ReminderType::Pruning);
        $rose->enableReminder(ReminderType::Deadheading);
        $manager->persist($rose);

        $lemonTree = Plant::propose(
            'Lemon tree',
            'Citrus × limon',
            'Rutaceae',
            new CareSheet(
                -3,
                true,
                Exposure::Sun,
                Watering::Medium,
                [3],
                [],
                [5, 6],
                'Thin out the crossing branches at the end of winter, before the growth starts.',
                'Semi-ripe cuttings in late spring, under cover, with rooting hormone.',
                self::confidence(Confidence::Medium),
                [new Source(Source::LLM_KNOWLEDGE), new Source(CareSheetFormData::USER_SOURCE)],
            ),
            $now->modify('-20 days'),
            notes: 'In a pot on the terrace: bring it in when it freezes.',
        );
        $lemonTree->validate($now->modify('-20 days'));
        $lemonTree->enableReminder(ReminderType::Frost);
        $manager->persist($lemonTree);

        $frontGarden = new Space('Front garden', Exposure::Sun, Soil::Clay, $now->modify('-30 days'));
        $manager->persist($frontGarden);
        $terrace = new Space('Terrace', Exposure::PartShade, Soil::Loamy, $now->modify('-30 days'));
        $manager->persist($terrace);
        $manager->persist(new Space('North bed', Exposure::Shade, Soil::Clay, $now->modify('-30 days')));

        // The rose was pruned last March, the lemon tree is in part shade and not sheltered yet. On the terrace, the
        // second rose and the lemon tree are neighbours.
        $roseByTheGate = new Planting('Rose by the gate', $frontGarden, 'Black spot on a few lower leaves.', $now->modify('-30 days'), 263311, 'Rosa canina');
        $roseByTheGate->recordCare(CareTask::Pruning, $now->modify('-8 months'));
        $roseByTheGate->recordCare(CareTask::Feeding, $now->modify('-6 months'));
        $roseByTheGate->linkTo($rose);
        $manager->persist($roseByTheGate);

        $lemonTreeOnTheTerrace = new Planting('Lemon tree on the terrace', $terrace, null, $now->modify('-20 days'), scientificName: 'Citrus × limon');
        $lemonTreeOnTheTerrace->recordCare(CareTask::Watering, $now->modify('-3 days'));
        $lemonTreeOnTheTerrace->linkTo($lemonTree);
        $manager->persist($lemonTreeOnTheTerrace);

        $roseOnTheTrellis = new Planting('Rose on the trellis', $terrace, null, $now->modify('-10 days'), 263311, 'Rosa canina');
        $roseOnTheTrellis->linkTo($rose);
        $manager->persist($roseOnTheTrellis);

        $manager->flush();

        // Conversations are not Doctrine entities: the purger leaves them
        if ($manager instanceof EntityManagerInterface) {
            $manager->getConnection()->executeStatement('DELETE FROM chat_conversation');
        }
    }

    /**
     * @return array<string, Confidence>
     */
    private static function confidence(Confidence $default): array
    {
        $confidence = [];
        foreach (CareSheetField::cases() as $field) {
            $confidence[$field->value] = $default;
        }

        return $confidence;
    }
}
