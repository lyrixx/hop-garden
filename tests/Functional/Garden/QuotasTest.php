<?php

namespace App\Tests\Functional\Garden;

use App\Factory\GardenFactory;
use App\Garden\Entity\Garden;
use App\Garden\PlantingRepository;
use App\Tests\Utils\Chat\WorkerTrait;
use App\Tests\Utils\Mercure\TurboStream;
use App\Tests\Utils\Security\LogInTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * What a garden may ask the LLM in a day (App\Garden\GardenQuotas): spent, the agent is not run, and the page says why,
 * in the language of the member. The other gardens keep theirs. The quotas are kept in a pool that outlives the kernel
 * of a request, as in production: emptied before each test.
 */
final class QuotasTest extends WebTestCase
{
    use LogInTrait;
    use WorkerTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get('garden_quota.cache')->clear();
        $this->logIn($this->client);
    }

    public function testTheChatSaysTheAssistantIsBackTomorrow(): void
    {
        $this->spendTheAgentRunsOf($this->gardenOfTheTest());
        $crawler = $this->client->request('GET', '/assistant');

        $this->client->submit($crawler->filter('form.chat-form')->form(), ['text' => 'hortensia'], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml']);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        [$error, $run] = TurboStream::parseAll((string) $this->client->getResponse()->getContent());
        self::assertSame('The assistant has answered enough for your garden today. It is back tomorrow.', $error->element()->text());
        self::assertSame(['replace', 'chat-run'], [$run->action, $run->target], 'The page stops waiting.');
        self::assertSame([], $this->runAgentMessages(), 'Not sent to the agent.');

        // Without Turbo too
        $this->client->submit($crawler->filter('form.chat-form')->form(), ['text' => 'hortensia']);
        self::assertResponseRedirects('/assistant', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'It is back tomorrow.');
    }

    public function testAPlantAddedWithoutSpeciesWaitsForItsCareSheet(): void
    {
        $this->spendTheAgentRunsOf($this->gardenOfTheTest());
        $crawler = $this->client->request('GET', '/garden/new');

        $this->client->submit($crawler->selectButton('Add to my garden')->form(), ['planting[name]' => 'The mystery shrub']);

        $planting = self::getContainer()->get(PlantingRepository::class)->findOneBy(['name' => 'The mystery shrub']) ?? self::fail('The plant is added anyway.');
        self::assertTrue($planting->hasDraftingFailed(), 'Its care sheet to retry.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'Its care sheet waits: the assistant has worked enough for your garden today');
        self::assertSelectorExists('form[action="/garden/' . $planting->getId() . '/draft"]', 'Retry, tomorrow.');
    }

    public function testTheQuotaIsTheOneOfEachGarden(): void
    {
        $this->spendTheAgentRunsOf(GardenFactory::createOne());
        $crawler = $this->client->request('GET', '/assistant');

        $this->client->submit($crawler->filter('form.chat-form')->form(), ['text' => 'hortensia']);

        self::assertCount(1, $this->runAgentMessages(), 'Another garden spent its own.');
    }

    private function spendTheAgentRunsOf(Garden $garden): void
    {
        $limiter = self::getContainer()->get('limiter.garden_agent_runs');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);
        $limiter->create('garden-' . $garden->getId())->consume(200);
    }
}
