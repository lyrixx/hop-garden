<?php

namespace App\Story;

use App\Factory\CareSheetFactory;
use App\Factory\GardenFactory;
use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Factory\SpaceFactory;
use App\Factory\UserFactory;
use App\Garden\Entity\GardenLocation;
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
 * "castor demo:reset": the administrator, three members and an account on the waiting list. Alice and Bob share "The
 * allotment" (Alice its owner): two validated plants with their reminders, their plantings in three spaces. Bob has "The
 * orchard" too, an apple tree in it: he goes from one garden to the other. Carol has "Carol's balcony", a lavender on
 * it: she sees nothing of theirs. No conversation, no notification.
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
        $alice = UserFactory::new()->approved()->create(['email' => 'alice@hop-garden.test', 'displayName' => 'Alice', 'preferredLocale' => SupportedLocale::English, 'registeredAt' => $now->modify('-31 days')]);
        $bob = UserFactory::new()->approved()->create(['email' => 'bob@hop-garden.test', 'displayName' => 'Bob', 'preferredLocale' => SupportedLocale::French, 'registeredAt' => $now->modify('-21 days')]);
        $carol = UserFactory::new()->approved()->create(['email' => 'carol@hop-garden.test', 'displayName' => 'Carol', 'preferredLocale' => SupportedLocale::English, 'registeredAt' => $now->modify('-15 days')]);
        UserFactory::new()->verified()->create(['email' => 'dave@hop-garden.test', 'displayName' => 'Dave', 'preferredLocale' => SupportedLocale::French, 'registeredAt' => $now->modify('-2 days')]);

        // Bob joins the day after (GardenFactory::withMembers()); the places of fixtures/http/open-meteo/geocoding/
        $allotment = GardenFactory::new()->ownedBy($alice)->withMembers($bob)->at(new GardenLocation('Lyon, Auvergne-Rhône-Alpes, France', 45.74846, 4.84671, 'Europe/Paris'))->create(['name' => 'The allotment', 'createdAt' => $now->modify('-30 days')]);
        $balcony = GardenFactory::new()->ownedBy($carol)->at(new GardenLocation('Lille, Hauts-de-France, France', 50.63297, 3.05858, 'Europe/Paris'))->create(['name' => "Carol's balcony", 'createdAt' => $now->modify('-14 days')]);
        $orchard = GardenFactory::new()->ownedBy($bob)->at(new GardenLocation('Nantes, Pays de la Loire, France', 47.21725, -1.55336, 'Europe/Paris'))->create(['name' => 'The orchard', 'createdAt' => $now->modify('-12 days')]);

        $rose = PlantFactory::new()
            ->validated($now->modify('-30 days'))
            ->withEnabledReminders(ReminderType::Pruning, ReminderType::Deadheading)
            ->create([
                'garden' => $allotment,
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
                'garden' => $allotment,
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

        $frontGarden = SpaceFactory::createOne(['garden' => $allotment, 'name' => 'Front garden', 'exposure' => Exposure::Sun, 'soil' => Soil::Clay, 'createdAt' => $now->modify('-30 days')]);
        $terrace = SpaceFactory::createOne(['garden' => $allotment, 'name' => 'Terrace', 'exposure' => Exposure::PartShade, 'soil' => Soil::Loamy, 'createdAt' => $now->modify('-30 days')]);
        SpaceFactory::createOne(['garden' => $allotment, 'name' => 'North bed', 'exposure' => Exposure::Shade, 'soil' => Soil::Clay, 'createdAt' => $now->modify('-30 days')]);

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

        // Carol's: a lavender in the window box of her balcony, pruned after its flowering
        $lavender = PlantFactory::new()
            ->validated($now->modify('-13 days'))
            ->withEnabledReminders(ReminderType::Pruning, ReminderType::Frost)
            ->create([
                'garden' => $balcony,
                'commonName' => 'English lavender',
                'scientificName' => 'Lavandula angustifolia',
                'family' => 'Lamiaceae',
                'careSheet' => CareSheetFactory::new()->withConfidence(Confidence::High)->with([
                    'minTemperatureC' => -15,
                    'frostSensitive' => false,
                    'exposure' => Exposure::Sun,
                    'watering' => Watering::Low,
                    'pruningMonths' => [3, 8],
                    'deadheadingMonths' => [7],
                    'cuttingMonths' => [8, 9],
                    'pruningAdvice' => 'Trim the flower stems after flowering, and shape the bush in March, never into the old wood.',
                    'cuttingAdvice' => 'Take 8 cm cuttings of non-flowering shoots in late summer, in a gritty compost.',
                    'sources' => [new Source('Trefle'), new Source(Source::LLM_KNOWLEDGE)],
                ]),
                'createdAt' => $now->modify('-14 days'),
                'trefleId' => 155623,
            ])
        ;
        $windowBox = SpaceFactory::createOne(['garden' => $balcony, 'name' => 'Window box', 'exposure' => Exposure::Sun, 'soil' => Soil::Sandy, 'createdAt' => $now->modify('-14 days')]);
        PlantingFactory::new()
            ->inSpace($windowBox)
            ->withCareRecord(CareTask::Watering, $now->modify('-5 days'))
            ->linkedTo($lavender)
            ->create(['name' => 'Lavender of the window box', 'createdAt' => $now->modify('-13 days'), 'trefleId' => 155623, 'scientificName' => 'Lavandula angustifolia'])
        ;

        // Bob's own: an apple tree at the end of his orchard, pruned in winter
        $appleTree = PlantFactory::new()
            ->validated($now->modify('-11 days'))
            ->withEnabledReminders(ReminderType::Pruning)
            ->create([
                'garden' => $orchard,
                'commonName' => 'Apple tree',
                'scientificName' => 'Malus domestica',
                'family' => 'Rosaceae',
                'careSheet' => CareSheetFactory::new()->withConfidence(Confidence::High)->with([
                    'minTemperatureC' => -30,
                    'frostSensitive' => false,
                    'exposure' => Exposure::Sun,
                    'watering' => Watering::Medium,
                    'pruningMonths' => [1, 2],
                    'deadheadingMonths' => [],
                    'cuttingMonths' => [],
                    'pruningAdvice' => 'Thin out the crossing branches in winter, out of frost, and shorten the long shoots by a third.',
                    'cuttingAdvice' => 'Grafted rather than taken from cuttings: ask a nursery for a scion on a rootstock.',
                    'sources' => [new Source(Source::LLM_KNOWLEDGE)],
                ]),
                'createdAt' => $now->modify('-12 days'),
            ])
        ;
        $hedge = SpaceFactory::createOne(['garden' => $orchard, 'name' => 'By the hedge', 'exposure' => Exposure::Sun, 'soil' => Soil::Loamy, 'createdAt' => $now->modify('-12 days')]);
        PlantingFactory::new()
            ->inSpace($hedge)
            ->linkedTo($appleTree)
            ->create(['name' => 'Old apple tree', 'createdAt' => $now->modify('-11 days'), 'scientificName' => 'Malus domestica'])
        ;

        // Conversations are no Doctrine entities: the demo starts without any, even loaded over the current data
        $this->connection->executeStatement('DELETE FROM chat_conversation');
    }
}
