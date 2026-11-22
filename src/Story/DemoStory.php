<?php

namespace App\Story;

use App\Factory\CareSheetFactory;
use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Factory\SpaceFactory;
use App\Factory\UserFactory;
use App\Garden\Model\CareTask;
use App\Garden\Model\Soil;
use App\Locale\Model\SupportedLocale;
use App\Plant\Form\Model\CareSheetFormData;
use App\Plant\Model\Confidence;
use App\Plant\Model\Exposure;
use App\Plant\Model\Source;
use App\Plant\Model\Watering;
use App\Reminder\Model\ReminderType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

/**
 * "castor demo:reset": the administrator, two members and an account on the waiting list; two validated plants with
 * their reminders, their plantings in three spaces of the garden, no conversation, no notification.
 */
#[AsFixture(name: 'demo')]
final class DemoStory extends Story
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly Connection $connection,
    ) {
    }

    public function build(): void
    {
        $now = $this->clock->now();

        // Their password is UserFactory::PASSWORD. Keep in sync with demo_reset() in castor.php, which prints them, and
        // with the login of video/capture.mjs
        UserFactory::new()->admin()->create(['email' => 'admin@hop-garden.test', 'displayName' => 'Admin', 'registeredAt' => $now->modify('-60 days')]);
        UserFactory::new()->approved()->create(['email' => 'alice@hop-garden.test', 'displayName' => 'Alice', 'preferredLocale' => SupportedLocale::English, 'registeredAt' => $now->modify('-30 days')]);
        UserFactory::new()->approved()->create(['email' => 'bob@hop-garden.test', 'displayName' => 'Bob', 'preferredLocale' => SupportedLocale::French, 'registeredAt' => $now->modify('-20 days')]);
        UserFactory::new()->verified()->create(['email' => 'dave@hop-garden.test', 'displayName' => 'Dave', 'preferredLocale' => SupportedLocale::French, 'registeredAt' => $now->modify('-2 days')]);

        $rose = PlantFactory::new()
            ->validated($now->modify('-30 days'))
            ->withEnabledReminders(ReminderType::Pruning, ReminderType::Deadheading)
            ->create([
                'commonName' => 'Climbing rose',
                'scientificName' => 'Rosa canina',
                'family' => 'Rosaceae',
                'careSheet' => CareSheetFactory::new()->withConfidence(Confidence::High)->with([
                    'minTemperatureC' => -20,
                    'frostSensitive' => false,
                    'exposure' => Exposure::Sun,
                    'watering' => Watering::Medium,
                    'pruningMonths' => [2, 3],
                    'deadheadingMonths' => [6, 7, 8, 9],
                    'cuttingMonths' => [9, 10],
                    'pruningAdvice' => 'Cut out dead wood, then shorten the side shoots to 2 or 3 buds.',
                    'cuttingAdvice' => 'Take hardwood cuttings of 20 cm in autumn and plant them deep in a sheltered spot.',
                    'sources' => [new Source('Trefle'), new Source(Source::LLM_KNOWLEDGE)],
                ]),
                'createdAt' => $now->modify('-30 days'),
                'trefleId' => 263311,
            ])
        ;

        $lemonTree = PlantFactory::new()
            ->validated($now->modify('-20 days'))
            ->withEnabledReminders(ReminderType::Frost)
            ->create([
                'commonName' => 'Lemon tree',
                'scientificName' => 'Citrus × limon',
                'family' => 'Rutaceae',
                'careSheet' => CareSheetFactory::new()->withConfidence(Confidence::Medium)->with([
                    'minTemperatureC' => -3,
                    'frostSensitive' => true,
                    'exposure' => Exposure::Sun,
                    'watering' => Watering::Medium,
                    'pruningMonths' => [3],
                    'deadheadingMonths' => [],
                    'cuttingMonths' => [5, 6],
                    'pruningAdvice' => 'Thin out the crossing branches at the end of winter, before the growth starts.',
                    'cuttingAdvice' => 'Semi-ripe cuttings in late spring, under cover, with rooting hormone.',
                    'sources' => [new Source(Source::LLM_KNOWLEDGE), new Source(CareSheetFormData::USER_SOURCE)],
                ]),
                'createdAt' => $now->modify('-20 days'),
                'notes' => 'In a pot on the terrace: bring it in when it freezes.',
            ])
        ;

        $frontGarden = SpaceFactory::createOne(['name' => 'Front garden', 'exposure' => Exposure::Sun, 'soil' => Soil::Clay, 'createdAt' => $now->modify('-30 days')]);
        $terrace = SpaceFactory::createOne(['name' => 'Terrace', 'exposure' => Exposure::PartShade, 'soil' => Soil::Loamy, 'createdAt' => $now->modify('-30 days')]);
        SpaceFactory::createOne(['name' => 'North bed', 'exposure' => Exposure::Shade, 'soil' => Soil::Clay, 'createdAt' => $now->modify('-30 days')]);

        // The rose was pruned last March, the lemon tree is in part shade and not sheltered yet. On the terrace, the
        // second rose and the lemon tree are neighbours.
        PlantingFactory::new()
            ->inSpace($frontGarden)
            ->withCareRecord(CareTask::Pruning, $now->modify('-8 months'))
            ->withCareRecord(CareTask::Feeding, $now->modify('-6 months'))
            ->linkedTo($rose)
            ->create(['name' => 'Rose by the gate', 'diseases' => 'Black spot on a few lower leaves.', 'createdAt' => $now->modify('-30 days'), 'trefleId' => 263311, 'scientificName' => 'Rosa canina'])
        ;

        PlantingFactory::new()
            ->inSpace($terrace)
            ->withCareRecord(CareTask::Watering, $now->modify('-3 days'))
            ->linkedTo($lemonTree)
            ->create(['name' => 'Lemon tree on the terrace', 'createdAt' => $now->modify('-20 days'), 'scientificName' => 'Citrus × limon'])
        ;

        PlantingFactory::new()
            ->inSpace($terrace)
            ->linkedTo($rose)
            ->create(['name' => 'Rose on the trellis', 'createdAt' => $now->modify('-10 days'), 'trefleId' => 263311, 'scientificName' => 'Rosa canina'])
        ;

        // Conversations are no Doctrine entities: the demo starts without any, even loaded over the current data
        $this->connection->executeStatement('DELETE FROM chat_conversation');
    }
}
