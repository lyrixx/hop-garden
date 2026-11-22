<?php

namespace App\Tests\Functional\Controller;

use App\Plant\PlantRepository;
use App\Tests\Utils\Chat\ScriptedGardener;
use App\Tests\Utils\Chat\WorkerTrait;
use App\Tests\Utils\Env\EnvVarsTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ChatControllerTest extends WebTestCase
{
    use EnvVarsTrait;
    use WorkerTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAnEmptyConversation(): void
    {
        $crawler = $this->client->request('GET', '/assistant');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#chat-log .chat-empty#chat-empty');
        self::assertSelectorNotExists('#chat-log .bubble');
        self::assertMatchesRegularExpression('#=chat%2F[0-9a-f-]{36}$#', $this->streamSource($crawler), 'The page listens to its conversation.');
        self::assertSelectorExists('#chat-run[data-busy="false"]');
        self::assertSelectorTextSame('.nav a.active', 'Assistant');
    }

    public function testALinkPrefillsTheMessage(): void
    {
        $crawler = $this->client->request('GET', '/plants/new');
        $crawler = $this->client->click($crawler->selectLink('Ask the assistant to draft it')->link());

        self::assertResponseIsSuccessful();
        self::assertSame('Draft the care sheet of a new species', $crawler->filter('form.chat-form input[name="text"]')->attr('value'));
    }

    public function testTheMessageIsEmptyWithoutLink(): void
    {
        $crawler = $this->client->request('GET', '/assistant');

        self::assertSame('', $crawler->filter('form.chat-form input[name="text"]')->attr('value'));
    }

    public function testAMessageIsQueuedForTheWorker(): void
    {
        $crawler = $this->client->request('GET', '/assistant');

        $this->client->submit($crawler->filter('form.chat-form')->form(), ['text' => '  hortensia ']);

        self::assertResponseRedirects('/assistant', 303);
        $messages = $this->runAgentMessages();
        self::assertCount(1, $messages);
        self::assertSame('hortensia', $messages[0]->text, 'Trimmed.');
        self::assertSame($this->conversationId($this->streamSource($crawler)), $messages[0]->conversationId, 'In the conversation of the page.');
    }

    public function testTurboSendsTheMessage(): void
    {
        $crawler = $this->client->request('GET', '/assistant');

        // As Turbo submits a form
        $this->client->submit($crawler->filter('form.chat-form')->form(), ['text' => 'hortensia'], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml']);

        // The page shows the message already, the answer comes through Mercure
        self::assertResponseStatusCodeSame(204);
        self::assertCount(1, $this->runAgentMessages());
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    public function testABlankMessageIsRefused(string $text): void
    {
        $crawler = $this->client->request('GET', '/assistant');

        $this->client->submit($crawler->filter('form.chat-form')->form(), ['text' => $text]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->runAgentMessages());
    }

    public function testATooLongMessageIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/assistant');

        $this->client->submit($crawler->filter('form.chat-form')->form(), ['text' => str_repeat('a', 501)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->runAgentMessages());
    }

    public function testAMessageRequiresAValidCsrfToken(): void
    {
        $this->client->request('POST', '/chat', ['text' => 'hortensia', '_token' => 'forged']);

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->runAgentMessages());
    }

    public function testTheConversationIsReplayedFromItsHistory(): void
    {
        $crawler = $this->client->request('GET', '/assistant');
        $this->client->submit($crawler->filter('form.chat-form')->form(), ['text' => 'hortensia']);
        $this->runTheWorker();
        $crawler = $this->client->request('GET', '/assistant');
        $this->client->submit($crawler->filter('form.chat-form')->form(), ['text' => 'paniculata']);
        $this->runTheWorker();

        $crawler = $this->client->request('GET', '/assistant');

        self::assertSelectorNotExists('.chat-empty');
        self::assertSame(['hortensia', 'paniculata'], $crawler->filter('#chat-log .bubble-user')->each(static fn ($bubble): string => $bubble->text()));
        self::assertSame([ScriptedGardener::QUESTION, ScriptedGardener::DRAFT_READY], $crawler->filter('#chat-log .bubble-assistant')->each(static fn ($bubble): string => $bubble->text()));
        self::assertSame('<p>' . ScriptedGardener::QUESTION . '</p>', $crawler->filter('.bubble-assistant')->first()->html(), 'In Markdown, rendered on the server.');
        self::assertSame('search_plant(query: "hortensia")', $crawler->filter('.tool-call code')->first()->text());
        self::assertSelectorExists('.chat-card turbo-frame:not([target]) article.plant-card.is-draft');
        self::assertSelectorTextContains('.chat-card', 'Panicled hydrangea');
    }

    public function testOutOfTheDemoModeTheToolCallsAreNotReplayed(): void
    {
        $this->setEnvVar('APP_DEMO', '0');
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $crawler = $this->client->request('GET', '/assistant');
        $this->client->submit($crawler->filter('form.chat-form')->form(), ['text' => 'paniculata']);
        $this->runTheWorker();

        $this->client->request('GET', '/assistant');

        self::assertSelectorNotExists('.tool-call');
        self::assertSelectorTextSame('#chat-log .bubble-user', 'paniculata');
        self::assertSelectorTextSame('#chat-log .bubble-assistant', ScriptedGardener::DRAFT_READY);
        self::assertSelectorExists('.chat-card article.plant-card.is-draft');
    }

    public function testADeletedDraftIsSaidSo(): void
    {
        $crawler = $this->client->request('GET', '/assistant');
        $this->client->submit($crawler->filter('form.chat-form')->form(), ['text' => 'paniculata']);
        $this->runTheWorker();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach (self::getContainer()->get(PlantRepository::class)->findAll() as $plant) {
            $entityManager->remove($plant);
        }
        $entityManager->flush();

        $this->client->request('GET', '/assistant');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.chat-card .callout', 'This draft no longer exists.');
    }

    public function testANewConversation(): void
    {
        $crawler = $this->client->request('GET', '/assistant');
        $this->client->submit($crawler->filter('form.chat-form')->form(), ['text' => 'hortensia']);
        $this->runTheWorker();
        $crawler = $this->client->request('GET', '/assistant');
        $topic = $this->streamSource($crawler);

        $this->client->submit($crawler->selectButton('New conversation')->form());

        self::assertResponseRedirects('/assistant', 303);
        $crawler = $this->client->followRedirect();
        self::assertNotSame($topic, $this->streamSource($crawler));
        self::assertSelectorExists('.chat-empty');
    }

    public function testANewConversationRequiresAValidCsrfToken(): void
    {
        $crawler = $this->client->request('GET', '/assistant');
        $topic = $this->streamSource($crawler);

        $this->client->request('POST', '/chat/new', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(401);
        $crawler = $this->client->request('GET', '/assistant');
        self::assertSame($topic, $this->streamSource($crawler), 'The same conversation.');
    }

    private function conversationId(string $hubUrl): string
    {
        self::assertSame(1, preg_match('#=chat%2F([0-9a-f-]{36})$#', $hubUrl, $matches));

        return $matches[1];
    }

    /**
     * The Mercure URL the chat listens to.
     */
    private function streamSource(Crawler $crawler): string
    {
        return (string) $crawler->filter('.chat turbo-mercure-stream-source')->attr('src');
    }
}
