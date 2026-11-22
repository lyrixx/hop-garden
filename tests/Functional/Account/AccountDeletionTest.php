<?php

namespace App\Tests\Functional\Account;

use App\Account\Entity\User;
use App\Account\UserRepository;
use App\Chat\ConversationRepository;
use App\Chat\Entity\Conversation;
use App\Factory\GardenFactory;
use App\Factory\NotificationFactory;
use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Factory\ReminderFactory;
use App\Factory\UserFactory;
use App\Garden\Entity\Garden;
use App\Garden\GardenRepository;
use App\Garden\Model\MembershipRole;
use App\Plant\PlantRepository;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * Alice deletes her account: her garden of her own goes with it, its plants, conversations and photos; the garden she
 * shares stays with the others, the oldest of them its owner; her conversations go everywhere.
 */
final class AccountDeletionTest extends WebTestCase
{
    use LogInTrait;

    private KernelBrowser $client;
    private string $photo;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->photo = self::getContainer()->getParameter('kernel.share_dir') . '/uploads/plantings/0123456789abcdef0123456789abcdef.png';
        @mkdir(\dirname($this->photo), 0o775, true);
        file_put_contents($this->photo, 'a photo');
    }

    protected function tearDown(): void
    {
        @unlink($this->photo);

        parent::tearDown();
    }

    public function testHerOwnGardenGoesHerSharedGardenStays(): void
    {
        $alice = UserFactory::new()->approved()->create(['displayName' => 'Alice']);
        $bob = UserFactory::new()->approved()->create(['displayName' => 'Bob']);
        $carol = UserFactory::new()->approved()->create(['displayName' => 'Carol']);
        $own = GardenFactory::new()->ownedBy($alice)->create(['name' => 'Her balcony', 'createdAt' => new \DateTimeImmutable('2026-09-01')]);
        // Bob joins before Carol: the oldest of the others
        $shared = GardenFactory::new()->ownedBy($alice)->withMembers($bob)->create(['name' => 'The allotment', 'createdAt' => new \DateTimeImmutable('2026-10-01')]);
        $shared->join($carol, new \DateTimeImmutable('2026-10-05'));
        PlantingFactory::new()->linkedTo(PlantFactory::new()->validated()->with(['garden' => $own]))->create(['garden' => $own, 'name' => 'Her lavender', 'photoFilename' => basename($this->photo)]);
        $sharedPlant = PlantFactory::new()->validated()->create(['garden' => $shared, 'commonName' => 'Their rose']);
        $reminder = ReminderFactory::createOne(['plant' => $sharedPlant]);
        NotificationFactory::createOne(['reminder' => $reminder, 'recipient' => $alice, 'message' => 'To Alice']);
        NotificationFactory::createOne(['reminder' => $reminder, 'recipient' => $bob, 'message' => 'To Bob']);
        $conversations = [
            'hers in her own garden' => $this->talk($alice, $own),
            'hers in the shared garden' => $this->talk($alice, $shared),
            'of Bob' => $this->talk($bob, $shared),
        ];
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $ids = ['alice' => $alice->getId(), 'own' => $own->getId(), 'shared' => $shared->getId(), 'bob' => $bob->getId()];

        $this->logIn($this->client, $alice);
        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->filter('form#deletion')->form(), ['account_deletion[currentPassword]' => UserFactory::PASSWORD]);
        self::assertResponseRedirects('/');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertNull(self::getContainer()->get(UserRepository::class)->find($ids['alice']));
        self::assertNull($entityManager->find(Garden::class, $ids['own']), 'Her own garden is gone.');
        self::assertFileDoesNotExist($this->photo, 'With its photos.');

        $shared = $entityManager->find(Garden::class, $ids['shared']) ?? self::fail('The shared garden stays.');
        self::assertSame([['Bob', MembershipRole::Owner], ['Carol', MembershipRole::Member]], array_map(static fn ($membership): array => [$membership->getUser()->getDisplayName(), $membership->getRole()], $shared->getMemberships()), 'The oldest of the others takes it over.');
        self::assertSame(['Their rose'], array_map(static fn ($plant): string => $plant->getCommonName(), self::getContainer()->get(PlantRepository::class)->findValidated($shared)));

        $store = self::getContainer()->get('ai.message_store.doctrine.dbal.chat');
        self::assertSame([], $store->load($conversations['hers in her own garden'])->getMessages());
        self::assertSame([], $store->load($conversations['hers in the shared garden'])->getMessages());
        self::assertCount(1, $store->load($conversations['of Bob'])->getMessages(), 'Not his.');
        self::assertSame([$conversations['of Bob']->toRfc4122()], array_map(static fn (UuidV7 $id): string => $id->toRfc4122(), self::getContainer()->get(ConversationRepository::class)->findIdsOfGarden($shared)));
        self::assertCount(1, self::getContainer()->get(GardenRepository::class)->findOfMember($entityManager->find(User::class, $ids['bob']) ?? self::fail()));
    }

    private function talk(User $member, Garden $garden): UuidV7
    {
        $id = new UuidV7();
        self::getContainer()->get(ConversationRepository::class)->add(Conversation::start($id, $member, $garden, new \DateTimeImmutable('2026-11-01')));
        self::getContainer()->get('ai.message_store.doctrine.dbal.chat')->save(MessageBag::forId($id, Message::ofUser('hortensia')));

        return $id;
    }
}
