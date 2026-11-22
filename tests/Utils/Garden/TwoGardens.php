<?php

namespace App\Tests\Utils\Garden;

use App\Account\Entity\User;
use App\Chat\ConversationRepository;
use App\Chat\Entity\Conversation;
use App\Factory\CareSheetFactory;
use App\Factory\GardenFactory;
use App\Factory\NotificationFactory;
use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Factory\SpaceFactory;
use App\Factory\UserFactory;
use App\Garden\Entity\Garden;
use App\Garden\Entity\GardenLocation;
use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Locale\Model\SupportedLocale;
use App\Plant\Entity\Plant;
use App\Plant\Model\Exposure;
use App\Reminder\Model\ReminderType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Uid\UuidV7;

/**
 * The gardens of the isolation tests, as the demo has them: Alice (English, its owner) and Bob (French) share an
 * allotment, with one resource of each kind; Carol has her balcony, where she must see none of them. Their names have no
 * character the templates escape, so that a page that shows one is caught.
 */
final readonly class TwoGardens
{
    public const string PHOTO = 'feedfacecafebeef0123456789abcdef.png';
    /**
     * What must never show to Carol: the names of their resources.
     */
    public const array NAMES = ['Allotment of Alice and Bob', 'Climbing rose', 'Rosa moschata', 'Draft of the allotment', 'Rose by the gate', 'Lemon tree drafting', 'Terrace of the allotment', 'Rose of the card', 'Space of the card'];

    private function __construct(
        public User $alice,
        public User $bob,
        public User $carol,
        public Garden $allotment,
        public Garden $balcony,
        public Plant $rose,
        public Plant $draft,
        public Space $terrace,
        public Planting $gate,
        public Planting $drafting,
        public Planting $proposedPlanting,
        public Space $proposedSpace,
        public UuidV7 $bobsConversation,
        public Plant $lavender,
        public Space $windowBox,
    ) {
    }

    public static function create(ContainerInterface $container): self
    {
        $alice = UserFactory::new()->approved()->create(['displayName' => 'Alice', 'preferredLocale' => SupportedLocale::English]);
        $bob = UserFactory::new()->approved()->create(['displayName' => 'Bob', 'preferredLocale' => SupportedLocale::French]);
        $carol = UserFactory::new()->approved()->create(['displayName' => 'Carol', 'preferredLocale' => SupportedLocale::English]);
        $allotment = GardenFactory::new()->ownedBy($alice)->withMembers($bob)->at(new GardenLocation('Lyon, Auvergne-Rhône-Alpes, France', 45.74846, 4.84671, 'Europe/Paris'))->create(['name' => 'Allotment of Alice and Bob']);
        $balcony = GardenFactory::new()->ownedBy($carol)->at(new GardenLocation('Lille, Hauts-de-France, France', 50.63297, 3.05858, 'Europe/Paris'))->create(['name' => "Carol's balcony"]);

        $rose = PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning, ReminderType::Frost)->create(['garden' => $allotment, 'commonName' => 'Climbing rose', 'scientificName' => 'Rosa moschata', 'trefleId' => 263311, 'careSheet' => CareSheetFactory::new(['frostSensitive' => true])]);
        $draft = PlantFactory::createOne(['garden' => $allotment, 'commonName' => 'Draft of the allotment']);
        $terrace = SpaceFactory::createOne(['garden' => $allotment, 'name' => 'Terrace of the allotment', 'exposure' => Exposure::Sun]);
        $gate = PlantingFactory::new()->inSpace($terrace)->linkedTo($rose)->create(['name' => 'Rose by the gate', 'scientificName' => 'Rosa moschata', 'photoFilename' => self::PHOTO]);
        $drafting = PlantingFactory::new()->drafting()->create(['garden' => $allotment, 'name' => 'Lemon tree drafting', 'scientificName' => 'Rosa moschata']);

        $entityManager = $container->get(EntityManagerInterface::class);
        $proposedPlanting = Planting::proposed((new UuidV7())->toRfc4122(), 'Rose of the card', $rose, $terrace, new \DateTimeImmutable('2026-11-01'));
        $proposedSpace = Space::proposed($allotment, (new UuidV7())->toRfc4122(), 'Space of the card', null, null, new \DateTimeImmutable('2026-11-01'));
        $entityManager->persist($proposedPlanting);
        $entityManager->persist($proposedSpace);

        foreach ($allotment->getMembers() as $member) {
            NotificationFactory::createOne(['reminder' => $rose->getReminders()[0], 'recipient' => $member, 'message' => 'Time to prune Climbing rose.']);
        }

        // Bob talks with the assistant of the allotment
        $bobsConversation = new UuidV7();
        $container->get(ConversationRepository::class)->add(Conversation::start($bobsConversation, $bob, $allotment, new \DateTimeImmutable('2026-11-01')));
        $container->get('ai.message_store.doctrine.dbal.chat')->save(MessageBag::forId($bobsConversation, Message::ofUser('Où tailler Climbing rose ?')));

        $lavender = PlantFactory::new()->validated()->create(['garden' => $balcony, 'commonName' => 'Lavender of Carol']);
        $windowBox = SpaceFactory::createOne(['garden' => $balcony, 'name' => 'Window box of Carol']);
        PlantingFactory::new()->inSpace($windowBox)->linkedTo($lavender)->create(['name' => 'Lavender in the window box']);
        $entityManager->flush();

        return new self($alice, $bob, $carol, $allotment, $balcony, $rose, $draft, $terrace, $gate, $drafting, $proposedPlanting, $proposedSpace, $bobsConversation, $lavender, $windowBox);
    }

    /**
     * The photo of the gate, on the disk of the test.
     */
    public function storePhoto(string $shareDir): string
    {
        $path = $shareDir . '/uploads/plantings/' . self::PHOTO;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o775, true);
        }
        file_put_contents($path, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true));

        return $path;
    }
}
