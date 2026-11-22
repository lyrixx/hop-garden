<?php

namespace App\Tests\Functional\Security;

use App\Account\Entity\User;
use App\Tests\Utils\Garden\TwoGardens;
use App\Tests\Utils\Security\LogInTrait;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Every update is private, and the hub has no anonymous subscriber (the Caddyfile): a member subscribes with the cookie
 * every page sets, which grants their own topics and those of their current garden, nothing else. As the hub matches
 * them: an exact topic, or a URL pattern ending with "*".
 */
final class MercureSubscriptionTest extends WebTestCase
{
    use LogInTrait;

    private const string COOKIE = 'mercure_access_token';

    private KernelBrowser $client;
    private TwoGardens $gardens;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->gardens = TwoGardens::create(self::getContainer());
    }

    public function testCarolCannotSubscribeToAConversationOfBob(): void
    {
        $bobsTopic = $this->conversationTopicOf($this->gardens->bob);
        $bobsGrants = $this->grantsOf($this->gardens->bob);
        self::assertTrue(self::grants($bobsGrants, $bobsTopic), 'Bob gets his own conversation.');

        $carolsTopic = $this->conversationTopicOf($this->gardens->carol);
        $carolsGrants = $this->grantsOf($this->gardens->carol);

        self::assertTrue(self::grants($carolsGrants, $carolsTopic), 'Carol gets hers.');
        self::assertFalse(self::grants($carolsGrants, $bobsTopic), 'Not the one of Bob.');
        self::assertSame(
            [
                ['urlpattern', \sprintf('https://hop-garden.test/users/%d/*', $this->gardens->carol->getId())],
                ['urlpattern', \sprintf('https://hop-garden.test/gardens/%d/*', $this->gardens->balcony->getId())],
                ['exact', \sprintf('https://hop-garden.test/users/%d/gardens/%d/notifications', $this->gardens->carol->getId(), $this->gardens->balcony->getId())],
                ['exact', $carolsTopic],
            ],
            $carolsGrants,
            'Her topics, and those of her garden: nothing of the allotment, where Bob drafts the care sheets of their plants.',
        );
        foreach ($carolsGrants as [, $pattern]) {
            self::assertFalse(self::grants([['urlpattern', $pattern]], \sprintf('https://hop-garden.test/gardens/%d/drafting/0196a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', $this->gardens->allotment->getId())));
            self::assertFalse(self::grants([['urlpattern', $pattern]], \sprintf('https://hop-garden.test/users/%d/gardens/%d/notifications', $this->gardens->bob->getId(), $this->gardens->allotment->getId())));
        }
    }

    public function testTheStreamsAskForTheCookie(): void
    {
        $this->logIn($this->client, $this->gardens->carol);

        $crawler = $this->client->request('GET', '/assistant');

        self::assertSame(2, $crawler->filter('turbo-mercure-stream-source[private]')->count(), 'The notifications and the conversation, with credentials.');
        self::assertSame(0, $crawler->filter('turbo-mercure-stream-source:not([private])')->count());
    }

    private function conversationTopicOf(User $member): string
    {
        $this->client->getCookieJar()->clear();
        $this->logIn($this->client, $member);
        $crawler = $this->client->request('GET', '/assistant');
        self::assertResponseIsSuccessful();

        return rawurldecode(explode('match=', (string) $crawler->filter('.chat turbo-mercure-stream-source')->attr('src'))[1]);
    }

    /**
     * The grants of the subscriber cookie of the last page, its signature checked with the key of the subscribers.
     *
     * @return list<array{string, string}> matcher type and pattern of each topic granted
     */
    private function grantsOf(User $member): array
    {
        $cookies = array_values(array_filter($this->client->getCookieJar()->all(), static fn ($cookie): bool => self::COOKIE === $cookie->getName()));
        self::assertCount(1, $cookies, \sprintf('The subscriber cookie of %s.', $member->getDisplayName()));
        self::assertSame('/.well-known/mercure', $cookies[0]->getPath(), 'Sent to the hub only.');
        self::assertTrue($cookies[0]->isHttpOnly());
        $jwt = $cookies[0]->getValue();
        $token = new Parser(new JoseEncoder())->parse($jwt);
        self::assertInstanceOf(UnencryptedToken::class, $token);
        self::assertTrue(new Validator()->validate($token, new SignedWith(new Sha256(), InMemory::plainText('!ChangeThisMercureSubscriberJWTKey!'))), 'Signed with the key of the subscribers, not of the publisher.');

        $grants = [];
        /** @var list<array{actions: list<string>, topics: list<array{match: string, match_type?: string}>}> $details */
        $details = $token->claims()->get('authorization_details');
        foreach ($details as $detail) {
            self::assertSame(['subscribe'], $detail['actions'], 'A subscriber publishes nothing.');
            foreach ($detail['topics'] as $topic) {
                // Without type, an exact topic
                $grants[] = [$topic['match_type'] ?? 'exact', $topic['match']];
            }
        }

        return $grants;
    }

    /**
     * @param list<array{string, string}> $grants
     */
    private static function grants(array $grants, string $topic): bool
    {
        foreach ($grants as [$type, $pattern]) {
            $matches = match ($type) {
                'exact' => $pattern === $topic,
                // The patterns of App\Mercure\Topics: a prefix, then anything
                'urlpattern' => str_ends_with($pattern, '/*') && str_starts_with($topic, substr($pattern, 0, -1)),
                default => self::fail('Unknown matcher type: ' . $type),
            };
            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
