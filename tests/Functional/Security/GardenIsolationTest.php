<?php

namespace App\Tests\Functional\Security;

use App\Chat\Message\RunAgent;
use App\Factory\PlantingFactory;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Planting;
use App\Garden\PlantingRepository;
use App\Plant\Model\PlantStatus;
use App\Plant\PlantRepository;
use App\Reminder\Model\ReminderType;
use App\Tests\Utils\Chat\WorkerTrait;
use App\Tests\Utils\Garden\TwoGardens;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every route of the application, as Carol: nothing of the garden of Alice and Bob, whose resources answer 404, the same
 * as a resource that does not exist, in GET as in POST (without a valid CSRF token: the voter comes first). The pages
 * without resource show her own garden. A route this test does not know makes it fail: add it to one of its lists.
 * The MCP tools: GardenIsolationMcpTest.
 */
final class GardenIsolationTest extends WebTestCase
{
    use LogInTrait;
    use WorkerTrait;

    /**
     * Public, or of the login, or of an address: no garden behind them.
     */
    private const array WITHOUT_GARDEN = [
        'home', 'legal_notice', 'legal_privacy', 'legal_terms', 'locale',
        'login', '_logout_main', 'register', 'register_check_email', 'register_verify', 'register_waiting_list',
        'reset_password_request', 'reset_password_check_email', 'reset_password_token', 'reset_password',
        // The account of Carol, and the signed link of a new email: no resource of a garden
        'account', 'account_email', 'account_email_confirm', 'account_password', 'account_delete',
        // The species search of Trefle, of no garden; its quota counts per garden (GardenQuotas)
        'ux_live_component',
        // Her subscriber cookie, renewed: her topics (MercureSubscriptionTest)
        'mercure_cookie',
    ];

    /**
     * Of her own garden: they show her garden, never theirs.
     */
    private const array OF_HER_GARDEN = [
        'chat' => '/assistant', 'garden' => '/garden', 'garden_new' => '/garden/new', 'plant_index' => '/plants',
        'plant_new' => '/plants/new', 'spaces' => '/spaces', 'space_new' => '/spaces/new', 'notifications' => '/notifications',
        'garden_create' => '/gardens/new', 'legacy_collection' => '/collection',
    ];

    private KernelBrowser $client;
    private TwoGardens $gardens;
    private string $photo;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->gardens = TwoGardens::create(self::getContainer());
        $this->photo = $this->gardens->storePhoto((string) self::getContainer()->getParameter('kernel.share_dir'));
        $this->logIn($this->client, $this->gardens->carol);
    }

    protected function tearDown(): void
    {
        @unlink($this->photo);

        parent::tearDown();
    }

    public function testEveryRouteIsCovered(): void
    {
        $routes = array_keys(self::getContainer()->get(RouterInterface::class)->getRouteCollection()->all());
        $covered = [
            ...self::WITHOUT_GARDEN,
            ...array_keys(self::OF_HER_GARDEN),
            ...array_keys($this->resourcesOfTheirGarden()),
            'chat_send', 'chat_new', '_mcp_endpoint_default', '_mcp_endpoint_agent',
            ...array_filter($routes, static fn (string $route): bool => str_starts_with($route, 'admin')),
        ];

        self::assertSame([], array_values(array_diff($routes, $covered)), 'A new route: add it to a list of this test.');
        self::assertSame([], array_values(array_diff($covered, $routes)), 'A route of this test is gone.');
    }

    public function testTheirResourcesAnswer404AsIfTheyDidNotExist(): void
    {
        foreach ($this->resourcesOfTheirGarden() as $route => $requests) {
            foreach ($requests as [$method, $url, $parameters]) {
                $this->client->request($method, $url, $parameters);
                self::assertResponseStatusCodeSame(404, \sprintf('%s %s (%s)', $method, $url, $route));

                $ghost = preg_replace('#/\d+(/|$)#', '/999999999$1', $url, 1);
                if ($ghost !== $url) {
                    $this->client->request($method, (string) $ghost, $parameters);
                    self::assertResponseStatusCodeSame(404, \sprintf('%s %s: the same answer as their resource', $method, $ghost));
                }
            }
        }

        $this->assertTheirGardenIsUntouched();
    }

    public function testThePagesOfHerGardenShowNothingOfTheirs(): void
    {
        foreach (self::OF_HER_GARDEN as $route => $url) {
            $this->client->request('GET', $url);
            if ($this->client->getResponse()->isRedirect()) {
                $this->client->followRedirect();
            }

            self::assertResponseIsSuccessful($route);
            $content = (string) $this->client->getResponse()->getContent();
            foreach (TwoGardens::NAMES as $name) {
                self::assertStringNotContainsString($name, $content, \sprintf('%s shows "%s".', $url, $name));
            }
        }
        self::assertSelectorTextContains('main', 'Lavender in the window box', 'Hers, on the last one: /garden.');

        // A species of theirs is no species to plant
        $this->client->request('GET', '/garden/new?plant=' . $this->gardens->rose->getId());
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Their cards, even untapped, add nothing of theirs to her garden: their care sheet and their space are gone for her.
     */
    public function testTheirCardsAreNotHers(): void
    {
        $url = '/proposals/plantings/0196a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b?' . http_build_query(['plant_id' => $this->gardens->rose->getId(), 'space_id' => $this->gardens->terrace->getId(), 'name' => 'Mine now']);

        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Climbing rose', $crawler->filter('main')->text());
        self::assertSelectorTextContains('main', 'This care sheet no longer exists');

        $this->client->request('POST', $url, ['planting_0196a1b2c3d47e5f8a9b0c1d2e3f4a5b' => ['name' => 'Mine now']]);
        self::assertSame([], self::getContainer()->get(PlantingRepository::class)->findBy(['name' => 'Mine now']), 'Nothing added.');
    }

    /**
     * Their ids in the body of her forms: not a choice, not a species of hers.
     */
    public function testTheirIdsInHerFormsAreRefused(): void
    {
        $crawler = $this->client->request('GET', '/garden/new');
        $values = $crawler->selectButton('Add to my garden')->form()->getPhpValues();
        $values['planting']['name'] = 'In their terrace';
        $values['planting']['space'] = (string) $this->gardens->terrace->getId();
        $this->client->request('POST', '/garden/new', $values);
        self::assertResponseStatusCodeSame(422, 'Their space is no choice of hers.');
        self::assertSame([], self::getContainer()->get(PlantingRepository::class)->findBy(['name' => 'In their terrace']));

        // A plant of hers of their species: their care sheet is not offered, nor taken
        $mine = PlantingFactory::new()->draftingFailed()->create(['garden' => $this->gardens->balcony, 'name' => 'My musk rose', 'scientificName' => 'Rosa moschata']);
        $crawler = $this->client->request('GET', '/garden/' . $mine->getId());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Climbing rose', $crawler->filter('main')->text());
        $this->client->request('POST', '/garden/' . $mine->getId() . '/plant', ['plant' => $this->gardens->rose->getId(), '_token' => $crawler->filter('input[name="_token"]')->first()->attr('value')]);
        self::assertResponseRedirects('/garden/' . $mine->getId(), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'This care sheet is not a validated one of the same species.');
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull(self::getContainer()->get(PlantingRepository::class)->find($mine->getId())?->getPlant());
    }

    public function testHerMessagesAndTheirsStayInTheirGarden(): void
    {
        $crawler = $this->client->request('GET', '/assistant');
        $this->client->submit($crawler->filter('form.chat-form')->form(), ['text' => 'What grows here?']);

        $messages = $this->runAgentMessages();
        self::assertCount(1, $messages);
        self::assertSame([$this->gardens->carol->getId(), $this->gardens->balcony->getId()], [$messages[0]->userId, $messages[0]->gardenId]);
        self::assertNotSame($this->gardens->bobsConversation->toRfc4122(), $messages[0]->conversationId);

        $this->client->request('POST', '/chat/new', ['_token' => 'forged']);
        self::assertResponseRedirects('/login', 302, 'No resource: a CSRF token first, then her own garden.');
    }

    public function testTheBackOfficeAndTheMcpServersAreNotHers(): void
    {
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection()->all() as $name => $route) {
            if (!str_starts_with($name, 'admin')) {
                continue;
            }

            $method = \in_array('GET', $route->getMethods(), true) || [] === $route->getMethods() ? 'GET' : 'POST';
            $this->client->request($method, (string) preg_replace('/\{\w+\}/', (string) $this->gardens->allotment->getId(), $route->getPath()));
            self::assertResponseStatusCodeSame(403, $name);
        }

        foreach (['/_mcp', '/_mcp/agent'] as $path) {
            $this->client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], content: '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
            self::assertResponseStatusCodeSame(401, $path . ': her session is no token of an MCP client.');
        }
    }

    /**
     * @return array<string, list<array{string, string, array<string, mixed>}>> by route, the requests to their resources
     */
    private function resourcesOfTheirGarden(): array
    {
        $gardens = $this->gardens;
        $gate = $gardens->gate->getId();
        $drafting = $gardens->drafting->getId();
        $rose = $gardens->rose->getId();
        $draft = $gardens->draft->getId();
        $terrace = $gardens->terrace->getId();
        $allotment = $gardens->allotment->getId();
        $plantingCard = '/proposals/plantings/' . $gardens->proposedPlanting->getProposalId() . '?' . http_build_query(['plant_id' => $rose, 'space_id' => $terrace, 'name' => 'Rose of the card']);
        $spaceCard = '/proposals/spaces/' . $gardens->proposedSpace->getProposalId() . '?' . http_build_query(['name' => 'Space of the card']);

        return [
            'garden_show' => [['GET', "/garden/{$gate}", []]],
            'garden_edit' => [['GET', "/garden/{$gate}/edit", []], ['POST', "/garden/{$gate}/edit", ['planting' => ['name' => 'Taken']]]],
            'garden_photo' => [['GET', "/garden/{$gate}/photos/" . TwoGardens::PHOTO, []]],
            'garden_draft' => [['POST', "/garden/{$drafting}/draft", []]],
            'garden_drafting' => [['GET', "/garden/{$drafting}/drafting", []]],
            'garden_link_plant' => [['POST', "/garden/{$drafting}/plant", ['plant' => $rose]]],
            'plant_show' => [['GET', "/plants/{$rose}", []], ['GET', "/plants/{$draft}", []]],
            'plant_card' => [['GET', "/plants/{$rose}/card", []]],
            'plant_validate' => [['POST', "/plants/{$draft}/validate", []]],
            'plant_edit' => [['GET', "/plants/{$rose}/edit", []], ['POST', "/plants/{$rose}/edit", ['care_sheet' => ['commonName' => 'Taken']]]],
            'plant_reminder' => [['POST', "/plants/{$rose}/reminders/pruning", ['enabled' => '0']]],
            'space_show' => [['GET', "/spaces/{$terrace}", []]],
            'space_edit' => [['GET', "/spaces/{$terrace}/edit", []], ['POST', "/spaces/{$terrace}/edit", ['space' => ['name' => 'Taken']]]],
            'planting_proposal' => [['GET', $plantingCard, []], ['POST', $plantingCard, []]],
            'space_proposal' => [['GET', $spaceCard, []], ['POST', $spaceCard, []]],
            'garden_switch' => [['POST', "/gardens/{$allotment}/switch", []]],
            'garden_settings' => [['GET', "/gardens/{$allotment}/settings", []], ['POST', "/gardens/{$allotment}/settings", ['garden' => ['name' => 'Taken']]]],
        ];
    }

    private function assertTheirGardenIsUntouched(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $plants = self::getContainer()->get(PlantRepository::class);

        self::assertSame([ReminderType::Pruning, ReminderType::Frost], $plants->get($this->gardens->allotment, $this->gardens->rose->getId())->getEnabledReminderTypes());
        self::assertSame(PlantStatus::Draft, $plants->get($this->gardens->allotment, $this->gardens->draft->getId())->getStatus());
        self::assertSame(['Climbing rose', 'Draft of the allotment'], array_map(static fn ($plant): string => $plant->getCommonName(), $plants->findBy(['garden' => $this->gardens->allotment], ['commonName' => 'ASC'])));
        self::assertSame(
            ['Lemon tree drafting', 'Rose by the gate', 'Rose of the card'],
            array_map(static fn (Planting $planting): string => $planting->getName(), self::getContainer()->get(PlantingRepository::class)->findAllByName($this->gardens->allotment)),
        );
        self::assertSame("Carol's balcony", $entityManager->find(Garden::class, $this->gardens->balcony->getId())?->getName());
        self::assertSame('Allotment of Alice and Bob', $entityManager->find(Garden::class, $this->gardens->allotment->getId())?->getName());
        $balcony = $this->gardens->balcony->getId();
        self::assertSame([], array_filter($this->runAgentMessages(), static fn (RunAgent $message): bool => $message->gardenId !== $balcony));
    }
}
