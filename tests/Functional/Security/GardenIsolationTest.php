<?php

namespace App\Tests\Functional\Security;

use App\Chat\Message\RunAgent;
use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Invitation;
use App\Garden\Entity\Planting;
use App\Garden\InvitationRepository;
use App\Garden\PlantingRepository;
use App\Plant\Entity\Plant;
use App\Plant\Model\PlantStatus;
use App\Plant\PlantRepository;
use App\Reminder\Model\ReminderType;
use App\Tests\Utils\Chat\WorkerTrait;
use App\Tests\Utils\Garden\GardenPathsTrait;
use App\Tests\Utils\Garden\TwoGardens;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every route of the application, as Carol: nothing of the garden of Alice and Bob. Their garden, /g/{their garden}/...,
 * answers 404 on every page, the same as a garden that does not exist; their resources too in the URL of her own garden,
 * the same as a resource that does not exist; in GET as in POST (without a valid CSRF token: the garden and the resource
 * come first). The pages of her garden show hers. A route this test does not know makes it fail: add it to one of its
 * lists. The MCP tools: GardenIsolationMcpTest.
 */
final class GardenIsolationTest extends WebTestCase
{
    use GardenPathsTrait;
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
        // Her subscriber cookie, renewed: her topics (MercureSubscriptionTest)
        'mercure_cookie',
        // The link of an invitation: whoever has its token joins its garden, it is all they need (AcceptInvitationTest)
        'invitation', 'invitation_register', 'invitation_accept',
    ];

    /**
     * Out of a garden, but leading to hers: the wizard of a new one, and the paths of before the gardens were in the URLs.
     */
    private const array TO_HER_GARDEN = [
        'garden_create' => '/gardens/new', 'assistant' => '/assistant', 'legacy_garden' => '/garden', 'legacy_plants' => '/plants',
        'legacy_spaces' => '/spaces', 'legacy_notifications' => '/notifications', 'legacy_collection' => '/collection',
    ];

    /**
     * The pages of a garden as a whole, after /g/{garden}: of hers, they show her garden, never theirs; of theirs, a 404.
     */
    private const array PAGES_OF_A_GARDEN = [
        'garden_home' => '', 'chat' => '/assistant', 'garden' => '/garden', 'garden_new' => '/garden/new', 'plant_index' => '/species',
        'plant_new' => '/species/new', 'spaces' => '/spaces', 'space_new' => '/spaces/new', 'notifications' => '/notifications',
        'garden_settings' => '/settings',
    ];

    /**
     * Their garden, and what Carol may type in its place.
     */
    private const string GHOST_GARDEN = 'no-such-garden';
    private const string GHOST_SLUG = 'no-such-slug';

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
            ...array_keys(self::TO_HER_GARDEN),
            ...array_keys(self::PAGES_OF_A_GARDEN),
            ...array_keys($this->actionsOfAGarden()),
            ...array_keys($this->resourcesOfTheirGarden()),
            '_mcp_endpoint_default', '_mcp_endpoint_agent',
            ...array_filter($routes, static fn (string $route): bool => str_starts_with($route, 'admin')),
        ];

        self::assertSame([], array_values(array_diff($routes, $covered)), 'A new route: add it to a list of this test.');
        self::assertSame([], array_values(array_diff($covered, $routes)), 'A route of this test is gone.');
    }

    public function testTheirGardenAnswers404AsIfItDidNotExist(): void
    {
        $requests = [
            ...array_map(static fn (string $path): array => [['GET', $path, []]], self::PAGES_OF_A_GARDEN),
            ...$this->actionsOfAGarden(),
            ...$this->resourcesOfTheirGarden(),
        ];
        foreach ($requests as $route => $routeRequests) {
            foreach ($routeRequests as [$method, $path, $parameters]) {
                foreach ([$this->gardens->allotment->getSlug(), self::GHOST_GARDEN] as $garden) {
                    $this->client->request($method, '/g/' . $garden . $path, $parameters);
                    self::assertResponseStatusCodeSame(404, \sprintf('%s /g/%s%s (%s)', $method, $garden, $path, $route));
                }
            }
        }

        $this->assertTheirGardenIsUntouched();
    }

    /**
     * Their slugs in the URL of her garden: not found in hers, the same as a slug that does not exist.
     */
    public function testTheirResourcesInTheUrlOfHerGardenAnswer404AsIfTheyDidNotExist(): void
    {
        $theirs = array_map(static fn (Plant|Planting|Space $resource): string => $resource->getSlug(), [$this->gardens->gate, $this->gardens->drafting, $this->gardens->rose, $this->gardens->draft, $this->gardens->terrace]);
        foreach ($this->resourcesOfTheirGarden() as $route => $requests) {
            foreach ($requests as [$method, $path, $parameters]) {
                $ghost = str_replace($theirs, self::GHOST_SLUG, $path);
                foreach (array_unique([$path, $ghost]) as $inHerGarden) {
                    $this->client->request($method, self::inGarden($inHerGarden, $this->gardens->balcony), $parameters);
                    self::assertResponseStatusCodeSame(404, \sprintf('%s %s (%s)', $method, self::inGarden($inHerGarden, $this->gardens->balcony), $route));
                }
            }
        }

        $this->assertTheirGardenIsUntouched();
    }

    /**
     * The same slug in both gardens, a species of the same Latin name: in the URL of her garden, hers.
     */
    public function testASlugOfBothGardensIsHersInTheUrlOfHerGarden(): void
    {
        $hers = PlantFactory::new()->validated()->create(['garden' => $this->gardens->balcony, 'commonName' => 'Musk rose of Carol', 'scientificName' => 'Rosa moschata']);
        self::assertSame($this->gardens->rose->getSlug(), $hers->getSlug(), 'Unique in each garden, not in the site.');

        $crawler = $this->client->request('GET', self::pathOf($hers));
        self::assertResponseIsSuccessful();
        self::assertSame('Musk rose of Carol', $crawler->filter('main .card-head h3')->text());
        self::assertStringNotContainsString('Climbing rose', $crawler->filter('main')->text());

        $this->client->request('GET', self::pathOf($this->gardens->rose));
        self::assertResponseStatusCodeSame(404, 'Theirs, in the URL of their garden.');
    }

    public function testThePagesOfHerGardenShowNothingOfTheirs(): void
    {
        $paths = [...array_map(fn (string $path): string => self::inGarden($path, $this->gardens->balcony), self::PAGES_OF_A_GARDEN), ...self::TO_HER_GARDEN];
        foreach ($paths as $route => $url) {
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
        self::assertSelectorTextContains('main', 'Lavender in the window box', 'Hers, on the last one: /collection, now her garden.');

        // A species of theirs is no species to plant
        $this->client->request('GET', self::inGarden('/garden/new?plant=' . $this->gardens->rose->getSlug(), $this->gardens->balcony));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Their cards, even untapped, add nothing of theirs to her garden: their care sheet and their space are gone for her.
     */
    public function testTheirCardsAreNotHers(): void
    {
        $url = self::inGarden('/proposals/plantings/0196a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', $this->gardens->balcony) . '?' . http_build_query(['plant_id' => $this->gardens->rose->getId()->toRfc4122(), 'space_id' => $this->gardens->terrace->getId()->toRfc4122(), 'name' => 'Mine now']);

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
        $crawler = $this->client->request('GET', self::inGarden('/garden/new', $this->gardens->balcony));
        $values = $crawler->selectButton('Add to my garden')->form()->getPhpValues();
        $values['planting']['name'] = 'In their terrace';
        $values['planting']['space'] = $this->gardens->terrace->getId()->toRfc4122();
        $this->client->request('POST', self::inGarden('/garden/new', $this->gardens->balcony), $values);
        self::assertResponseStatusCodeSame(422, 'Their space is no choice of hers.');
        self::assertSame([], self::getContainer()->get(PlantingRepository::class)->findBy(['name' => 'In their terrace']));

        // A plant of hers of their species: their care sheet is not offered, nor taken
        $mine = PlantingFactory::new()->draftingFailed()->create(['garden' => $this->gardens->balcony, 'name' => 'My musk rose', 'scientificName' => 'Rosa moschata']);
        $crawler = $this->client->request('GET', self::pathOf($mine));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Climbing rose', $crawler->filter('main')->text());
        $this->client->request('POST', self::pathOf($mine) . '/plant', ['plant' => $this->gardens->rose->getId()->toRfc4122(), '_token' => $crawler->filter('input[name="_token"]')->first()->attr('value')]);
        self::assertResponseRedirects(self::pathOf($mine), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'This care sheet is not a validated one of the same species.');
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull(self::getContainer()->get(PlantingRepository::class)->find($mine->getId())?->getPlant());
    }

    public function testHerMessagesAndTheirsStayInTheirGarden(): void
    {
        $crawler = $this->client->request('GET', self::inGarden('/assistant', $this->gardens->balcony));
        $this->client->submit($crawler->filter('form.chat-form')->form(), ['text' => 'What grows here?']);

        $messages = $this->runAgentMessages();
        self::assertCount(1, $messages);
        self::assertSame([$this->gardens->carol->getId()->toRfc4122(), $this->gardens->balcony->getId()->toRfc4122()], [$messages[0]->userId, $messages[0]->gardenId]);
        self::assertNotSame($this->gardens->bobsConversation->toRfc4122(), $messages[0]->conversationId);

        $this->client->request('POST', self::inGarden('/chat/new', $this->gardens->balcony), ['_token' => 'forged']);
        self::assertResponseRedirects('/login', 302, 'Her garden: a CSRF token first.');
    }

    public function testTheBackOfficeAndTheMcpServersAreNotHers(): void
    {
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection()->all() as $name => $route) {
            if (!str_starts_with($name, 'admin')) {
                continue;
            }

            $method = \in_array('GET', $route->getMethods(), true) || [] === $route->getMethods() ? 'GET' : 'POST';
            // An id of theirs, as a word: some parameters are words only
            $this->client->request($method, (string) preg_replace('/\{\w+\}/', $this->gardens->allotment->getId()->toHex(), $route->getPath()));
            self::assertResponseStatusCodeSame(403, $name);
        }

        foreach (['/_mcp', '/_mcp/agent'] as $path) {
            $this->client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], content: '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
            self::assertResponseStatusCodeSame(401, $path . ': her session is no token of an MCP client.');
        }
    }

    /**
     * The forms of a garden as a whole, after /g/{garden}: the chat, and the species search of its forms.
     *
     * @return array<string, list<array{string, string, array<string, mixed>}>> by route
     */
    private function actionsOfAGarden(): array
    {
        return [
            'chat_send' => [['POST', '/chat', ['text' => 'Mine now']]],
            'chat_new' => [['POST', '/chat/new', []]],
            'ux_live_component' => [['GET', '/_components/SpeciesPicker', []]],
        ];
    }

    /**
     * After /g/{garden}: their resources, by their slugs and their UUIDs.
     *
     * @return array<string, list<array{string, string, array<string, mixed>}>> by route
     */
    private function resourcesOfTheirGarden(): array
    {
        $gardens = $this->gardens;
        $gate = $gardens->gate->getSlug();
        $drafting = $gardens->drafting->getSlug();
        $rose = $gardens->rose->getSlug();
        $draft = $gardens->draft->getSlug();
        $terrace = $gardens->terrace->getSlug();
        $plantingCard = '/proposals/plantings/' . $gardens->proposedPlanting->getProposalId() . '?' . http_build_query(['plant_id' => $gardens->rose->getId()->toRfc4122(), 'space_id' => $gardens->terrace->getId()->toRfc4122(), 'name' => 'Rose of the card']);
        $spaceCard = '/proposals/spaces/' . $gardens->proposedSpace->getProposalId() . '?' . http_build_query(['name' => 'Space of the card']);

        return [
            'garden_show' => [['GET', "/garden/{$gate}", []]],
            'garden_edit' => [['GET', "/garden/{$gate}/edit", []], ['POST', "/garden/{$gate}/edit", ['planting' => ['name' => 'Taken']]]],
            'garden_photo' => [['GET', "/garden/{$gate}/photos/" . TwoGardens::PHOTO, []]],
            'garden_draft' => [['POST', "/garden/{$drafting}/draft", []]],
            'garden_drafting' => [['GET', "/garden/{$drafting}/drafting", []]],
            'garden_link_plant' => [['POST', "/garden/{$drafting}/plant", ['plant' => $gardens->rose->getId()->toRfc4122()]]],
            'plant_show' => [['GET', "/species/{$rose}", []], ['GET', "/species/{$draft}", []]],
            'plant_card' => [['GET', "/species/{$rose}/card", []]],
            'plant_validate' => [['POST', "/species/{$draft}/validate", []]],
            'plant_edit' => [['GET', "/species/{$rose}/edit", []], ['POST', "/species/{$rose}/edit", ['care_sheet' => ['commonName' => 'Taken']]]],
            'plant_reminder' => [['POST', "/species/{$rose}/reminders/pruning", ['enabled' => '0']]],
            'space_show' => [['GET', "/spaces/{$terrace}", []]],
            'space_edit' => [['GET', "/spaces/{$terrace}/edit", []], ['POST', "/spaces/{$terrace}/edit", ['space' => ['name' => 'Taken']]]],
            'planting_proposal' => [['GET', $plantingCard, []], ['POST', $plantingCard, []]],
            'space_proposal' => [['GET', $spaceCard, []], ['POST', $spaceCard, []]],
        ];
    }

    /**
     * @return list<array{string, string, array<string, mixed>}>
     */
    private static function throughBoth(string $path, int $theirs, int $hers): array
    {
        return [['POST', "/gardens/{$theirs}{$path}", []], ['POST', "/gardens/{$hers}{$path}", []]];
    }

    private function assertTheirGardenIsUntouched(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $plants = self::getContainer()->get(PlantRepository::class);

        self::assertSame([ReminderType::Pruning, ReminderType::Frost], $plants->get($this->gardens->allotment, $this->gardens->rose->getId()->toRfc4122())->getEnabledReminderTypes());
        self::assertSame(PlantStatus::Draft, $plants->get($this->gardens->allotment, $this->gardens->draft->getId()->toRfc4122())->getStatus());
        self::assertSame(['Climbing rose', 'Draft of the allotment'], array_map(static fn ($plant): string => $plant->getCommonName(), $plants->findBy(['garden' => $this->gardens->allotment], ['commonName' => 'ASC'])));
        self::assertSame(
            ['Lemon tree drafting', 'Rose by the gate', 'Rose of the card'],
            array_map(static fn (Planting $planting): string => $planting->getName(), self::getContainer()->get(PlantingRepository::class)->findAllByName($this->gardens->allotment)),
        );
        self::assertSame("Carol's balcony", $entityManager->find(Garden::class, $this->gardens->balcony->getId())?->getName());
        $allotment = $entityManager->find(Garden::class, $this->gardens->allotment->getId()) ?? self::fail('Their garden is gone.');
        self::assertSame('Allotment of Alice and Bob', $allotment->getName());
        self::assertSame(['Alice', 'Bob'], array_map(static fn ($member): string => $member->getDisplayName(), $allotment->getMembers()));
        $invitations = self::getContainer()->get(InvitationRepository::class);
        self::assertSame(['erin@example.com'], array_map(static fn (Invitation $invitation): string => $invitation->getEmail(), $invitations->findOpenOfGarden($allotment)));
        self::assertSame($this->gardens->invitation->getId()->toRfc4122(), $invitations->findOneByToken(TwoGardens::INVITATION_TOKEN)?->getId()->toRfc4122(), 'Its link still works.');
        self::assertSame([], $invitations->findBy(['email' => 'mallory@example.com']));
        self::assertSame([], array_filter($this->runAgentMessages(), static fn (RunAgent $message): bool => $message->gardenId !== $balcony));
    }
}
