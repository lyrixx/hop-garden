<?php

namespace App\Tests\Functional\Controller;

use App\Factory\CareSheetFactory;
use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Factory\SpaceFactory;
use App\Garden\DraftCareSheetHandler;
use App\Garden\Entity\Planting;
use App\Garden\Message\DraftCareSheet;
use App\Garden\Model\CareTask;
use App\Garden\Model\Soil;
use App\Garden\PlantingRepository;
use App\Plant\Entity\Plant;
use App\Plant\Model\Exposure;
use App\Reminder\Model\ReminderType;
use App\Tests\Utils\Chat\ScriptedGardener;
use App\Tests\Utils\Env\EnvVarsTrait;
use App\Tests\Utils\Http\UnavailableDataSources;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class GardenControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use EnvVarsTrait;
    use LogInTrait;

    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    private const string GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    private KernelBrowser $client;
    private string $tmpDir;

    /** @var list<string> */
    private array $storedPhotos = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->logIn($this->client);
        $this->tmpDir = sys_get_temp_dir() . '/hop-garden-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach ([...glob($this->tmpDir . '/*') ?: [], ...$this->storedPhotos] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    public function testAddAPlantingWithItsSpeciesAPhotoAndItsSpace(): void
    {
        self::mockTime('2026-11-21 10:00');
        $northBed = SpaceFactory::createOne(['name' => 'North bed', 'exposure' => Exposure::PartShade, 'soil' => Soil::Clay]);
        $rose = PlantingFactory::new()->inSpace($northBed)->create(['name' => 'Rose by the gate']);
        $crawler = $this->client->request('GET', '/garden/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Add to my garden')->form();
        $form['planting[name]'] = 'Hortensia du portail';
        // Set by the species picker
        $form['planting[trefleId]'] = '191052';
        $form['planting[scientificName]'] = 'Hydrangea paniculata';
        $form['planting[photo]']->upload($this->file('../../holiday.php.png', self::PNG));
        $form['planting[space]']->select((string) $northBed->getId());
        $form['planting[diseases]'] = 'Powdery mildew on the lower leaves.';
        $form['planting[careRecords][pruning]'] = '2026-03-10';
        $this->client->submit($form);

        $planting = $this->lastPlanting();
        self::assertResponseRedirects('/garden/' . $planting->getId(), 303);
        self::assertSame('Hortensia du portail', $planting->getName());
        self::assertSame(191052, $planting->getTrefleId());
        self::assertSame('Hydrangea paniculata', $planting->getScientificName());
        self::assertSame('North bed', $planting->getSpace()?->getName());
        self::assertSame('Powdery mildew on the lower leaves.', $planting->getDiseases());
        self::assertSame('2026-03-10', $planting->lastDone(CareTask::Pruning)?->format('Y-m-d'));
        self::assertNull($planting->lastDone(CareTask::Watering));
        self::assertSame(['Rose by the gate'], array_map(static fn (Planting $neighbour): string => $neighbour->getName(), $planting->getNeighbours()));
        self::assertSame(['Hortensia du portail'], array_map(static fn (Planting $neighbour): string => $neighbour->getName(), $this->reload($rose)->getNeighbours()), 'The plants of a space are neighbours.');

        $photoFilename = $planting->getPhotoFilename() ?? self::fail('The photo is stored.');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $photoFilename, 'The server names the file, not the client.');
        $photoPath = self::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/plantings/' . $photoFilename;
        $this->storedPhotos[] = $photoPath;
        self::assertFileExists($photoPath);

        // No validated plant of this species: the agent drafts its care sheet, in the worker
        self::assertTrue($planting->isDrafting());
        self::assertSame([[$planting->getId(), $planting->getConversationId()]], $this->draftMessages());

        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('.garden-photo img[src="/uploads/plantings/' . $photoFilename . '"]');
        self::assertSelectorTextContains('.garden-pending', 'The assistant is drafting the care sheet of Hortensia du portail');
        self::assertStringEndsWith('=' . rawurlencode('chat/' . $planting->getConversationId()), (string) $crawler->filter('[data-controller="drafting"] turbo-mercure-stream-source')->attr('src'), 'The page listens to the agent drafting.');
        self::assertSelectorExists('[data-controller="drafting"][data-drafting-drafting-value="true"] #drafting-tool-calls.garden-tool-calls');
    }

    public function testAPlantOfASpeciesAlreadyValidatedNeedsNoAgent(): void
    {
        self::mockTime('2026-03-15 10:00');
        $hydrangea = PlantFactory::new()->validated()->create(['commonName' => 'Bigleaf hydrangea', 'careSheet' => CareSheetFactory::new(['pruningMonths' => [3]])]);
        $crawler = $this->client->request('GET', '/garden/new');

        $this->client->submit($crawler->selectButton('Add to my garden')->form(), [
            'planting[name]' => 'Hydrangea by the shed',
            'planting[trefleId]' => '191047',
            'planting[scientificName]' => 'Hydrangea macrophylla',
        ]);

        $planting = $this->lastPlanting();
        self::assertSame($hydrangea->getId(), $planting->getPlant()?->getId());
        self::assertSame([], $this->draftMessages());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Hydrangea by the shed is in your garden, with the care sheet of Bigleaf hydrangea.');
        self::assertSelectorExists('.recommendation-action');
    }

    public function testRetryAfterARunWithoutDraft(): void
    {
        $planting = PlantingFactory::new()->draftingFailed()->create(['name' => 'The mystery shrub', 'createdAt' => new \DateTimeImmutable('2026-03-01')]);
        $failedConversationId = $planting->getConversationId();

        $crawler = $this->client->request('GET', '/garden/' . $planting->getId());
        self::assertSelectorTextContains('.garden-failure', 'The assistant ended without drafting a care sheet. What you entered is kept.');
        self::assertCount(0, $crawler->filter('[data-controller="drafting"][data-drafting-drafting-value="false"] turbo-mercure-stream-source'), 'Nothing to listen to.');

        $this->client->submit($crawler->selectButton('Retry')->form());

        self::assertResponseRedirects('/garden/' . $planting->getId());
        $planting = $this->reload($planting);
        self::assertTrue($planting->isDrafting());
        self::assertNotSame($failedConversationId, $planting->getConversationId(), 'A new conversation for a new attempt.');
        self::assertSame([[$planting->getId(), $planting->getConversationId()]], $this->draftMessages());

        $this->client->request('GET', '/garden/' . $planting->getId() . '/drafting');
        self::assertSame('{"drafting":true}', $this->client->getResponse()->getContent());
    }

    public function testTheDraftingShowsItsToolCalls(): void
    {
        $planting = $this->draftWithoutResult();

        $crawler = $this->client->request('GET', '/garden/' . $planting->getId());

        self::assertSame(['list_collection()'], $crawler->filter('.garden-tool-calls .tool-call code')->each(static fn ($code): string => $code->text()));
    }

    public function testOutOfTheDemoModeTheDraftingShowsNoToolCall(): void
    {
        $this->setEnvVar('APP_DEMO', '0');
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->logIn($this->client);
        $planting = $this->draftWithoutResult();

        $crawler = $this->client->request('GET', '/garden/' . $planting->getId());

        self::assertSelectorTextContains('.garden-failure', 'The assistant ended without drafting a care sheet.');
        self::assertSame('', $crawler->filter('.garden-tool-calls')->html(), 'Empty, so that the CSS hides it.');
    }

    public function testANameTrefleDoesNotKnowIsEnough(): void
    {
        $crawler = $this->client->request('GET', '/garden/new');

        $this->client->submit($crawler->selectButton('Add to my garden')->form(), ['planting[name]' => '  The mystery shrub  ']);

        $planting = $this->lastPlanting();
        self::assertResponseRedirects('/garden/' . $planting->getId());
        self::assertSame('The mystery shrub', $planting->getName());
        self::assertNull($planting->getScientificName());
        self::assertNull($planting->getTrefleId());
        self::assertNull($planting->getPhotoFilename());
        self::assertNull($planting->getSpace(), 'Until the user chooses one.');

        $this->client->followRedirect();
        self::assertSelectorExists('.plant-card-image svg.icon-hop');
    }

    #[DataProvider('provideOnlyJpegPngAndWebpPhotosAreAcceptedCases')]
    public function testOnlyJpegPngAndWebpPhotosAreAccepted(string $clientName, string $base64Content): void
    {
        $crawler = $this->client->request('GET', '/garden/new');
        $form = $crawler->selectButton('Add to my garden')->form();
        $form['planting[name]'] = 'Rose';
        $form['planting[photo]']->upload($this->file($clientName, $base64Content));

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'Send a JPEG, PNG or WebP photo.');
        self::assertSame(0, self::getContainer()->get(PlantingRepository::class)->count());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideOnlyJpegPngAndWebpPhotosAreAcceptedCases(): iterable
    {
        yield 'a GIF' => ['photo.gif', self::GIF];
        yield 'a script disguised as a JPEG' => ['photo.jpg', base64_encode('<?php echo "hello";')];
    }

    public function testTheSpeciesSurvivesAFormSentBack(): void
    {
        $crawler = $this->client->request('GET', '/garden/new');
        $form = $crawler->selectButton('Add to my garden')->form();
        $form['planting[name]'] = 'Hortensia du portail';
        // Set by the species picker
        $form['planting[trefleId]'] = '191052';
        $form['planting[scientificName]'] = 'Hydrangea paniculata';
        $form['planting[photo]']->upload($this->file('photo.gif', self::GIF));

        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.garden-species-chosen:not([hidden]) em', 'Hydrangea paniculata');
        self::assertSelectorNotExists('.garden-species-candidates button', 'No other species to pick once one is.');
        $form = $crawler->selectButton('Add to my garden')->form();
        self::assertSame('Hortensia du portail', $form['planting[name]']->getValue());
        self::assertSame('191052', $form['planting[trefleId]']->getValue());
        self::assertSame('Hydrangea paniculata', $form['planting[scientificName]']->getValue());

        $this->client->submit($form);

        $planting = $this->lastPlanting();
        self::assertResponseRedirects('/garden/' . $planting->getId(), 303);
        self::assertSame(191052, $planting->getTrefleId());
        self::assertSame('Hydrangea paniculata', $planting->getScientificName());
    }

    public function testCareDatesFollowTheClock(): void
    {
        self::mockTime('2027-06-01 10:00');
        $crawler = $this->client->request('GET', '/garden/new');
        $form = $crawler->selectButton('Add to my garden')->form();

        $this->client->submit($form, ['planting[name]' => 'Rose', 'planting[careRecords][watering]' => '2027-06-02']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'This date is in the future.');

        $this->client->submit($form, ['planting[name]' => 'Rose', 'planting[careRecords][watering]' => '2027-05-20']);
        self::assertResponseRedirects();
    }

    public function testAPlantingUsesAValidatedCareSheetOfTheSameSpecies(): void
    {
        self::mockTime('2026-03-15 10:00');
        $hydrangea = PlantFactory::new()->validated()->create(['commonName' => 'Bigleaf hydrangea', 'careSheet' => CareSheetFactory::new(['pruningMonths' => [3], 'exposure' => Exposure::PartShade])]);
        PlantFactory::createOne(['commonName' => 'Draft hydrangea']);
        $shed = SpaceFactory::createOne(['name' => 'By the shed', 'exposure' => Exposure::Shade, 'soil' => Soil::Loamy, 'createdAt' => new \DateTimeImmutable('2026-03-01')]);
        $planting = PlantingFactory::new()->inSpace($shed)->create(['name' => 'Hydrangea by the shed', 'createdAt' => new \DateTimeImmutable('2026-03-01'), 'trefleId' => 191047, 'scientificName' => 'hydrangea MACROPHYLLA']);

        $crawler = $this->client->request('GET', '/garden/' . $planting->getId());
        self::assertSelectorTextContains('.garden-failure', 'No care sheet yet.');
        self::assertSame(['Use the sheet of Bigleaf hydrangea'], $crawler->filter('form[action$="/plant"] button')->each(static fn ($button): string => $button->text()), 'Only the validated plants of the same species.');

        $this->client->submit($crawler->selectButton('Use the sheet of Bigleaf hydrangea')->form());

        self::assertResponseRedirects('/garden/' . $planting->getId());
        self::assertSame($hydrangea->getId(), $this->reload($planting)->getPlant()?->getId());

        $crawler = $this->client->followRedirect();
        self::assertSelectorNotExists('.garden-pending');
        self::assertSame(['Pruning: Time to prune it (March). Cut the faded flower heads back to the first pair of buds.'], $crawler->filter('.recommendation-action')->each(
            static fn ($item): string => $item->filter('strong')->text() . ': ' . $item->filter('p')->last()->text(),
        ));
        self::assertAnySelectorTextContains('.recommendation-info', 'It prefers part shade and gets shade here');
    }

    public function testADraftCareSheetIsWaitingForReview(): void
    {
        $planting = PlantingFactory::new()->linkedTo(PlantFactory::new())->create(['name' => 'Hydrangea by the shed', 'createdAt' => new \DateTimeImmutable('2026-03-01')]);

        $this->client->request('GET', '/garden/' . $planting->getId());

        self::assertSelectorTextContains('#recommendations', 'is waiting for your review');
        self::assertSelectorNotExists('.recommendation');
        // The species in short: reviewed and validated on its own page
        $plant = $planting->getPlant() ?? throw new \LogicException('Linked above.');
        self::assertSelectorExists('.species-summary.is-draft a.button[href="/plants/' . $plant->getId() . '"]');
        self::assertSelectorTextContains('.species-summary', 'Validate its care sheet to set reminders.');
        self::assertSelectorNotExists('.plant-card, form[action$="/validate"]', 'Not the card: it belongs to the page of the species.');
        // The parts the worker streams once the draft is proposed, see App\Garden\DraftCareSheetHandler
        self::assertSelectorExists('#planting-' . $planting->getId() . '-draft-status [data-drafting-target="drafted"]');
        self::assertSelectorExists('#planting-' . $planting->getId() . '-species > .species-summary');
    }

    public function testACareSheetOfAnotherSpeciesIsRefused(): void
    {
        $otherSpecies = PlantFactory::new()->validated()->create(['commonName' => 'Rose', 'scientificName' => 'Rosa canina']);
        PlantFactory::new()->validated()->create();
        $planting = PlantingFactory::createOne(['name' => 'Hydrangea by the shed', 'createdAt' => new \DateTimeImmutable('2026-03-01'), 'scientificName' => 'Hydrangea macrophylla']);
        $crawler = $this->client->request('GET', '/garden/' . $planting->getId());

        // A forged plant id in the form of the hydrangea sheet
        $this->client->submit($crawler->filter('form[action$="/plant"]')->form(), ['plant' => (string) $otherSpecies->getId()]);

        self::assertResponseRedirects('/garden/' . $planting->getId());
        self::assertNull($this->reload($planting)->getPlant());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'This care sheet is not a validated one of the same species.');
    }

    public function testTheGardenListsTheDraftsOfTheChatThenThePlantings(): void
    {
        PlantFactory::createOne(['commonName' => 'Panicled hydrangea']);
        PlantingFactory::createOne(['name' => 'The mystery shrub']);
        $shedDraft = PlantFactory::createOne();
        PlantingFactory::new()->linkedTo($shedDraft)->create(['name' => 'Hydrangea by the shed', 'trefleId' => 191047, 'scientificName' => 'Hydrangea macrophylla']);
        PlantingFactory::new()->of(PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning)->create(['commonName' => 'Climbing rose']))->create();

        $crawler = $this->client->request('GET', '/garden');

        self::assertResponseIsSuccessful();
        self::assertSame(['drafts', 'plant-grid'], $crawler->filter('.drafts, .plant-grid')->each(static fn ($node): string => (string) $node->attr('class')), 'The drafts on top.');
        self::assertSame(['Panicled hydrangea'], $crawler->filter('.drafts li a')->each(static fn ($link): string => $link->text()), 'Only the draft of the chat.');
        self::assertSelectorNotExists('.drafts a[href="/plants/' . $shedDraft->getId() . '"]', 'The draft of a planting waits in its tile.');
        self::assertSame(
            [
                'Climbing rose' => ['Care sheet', 'Pruning'],
                'Hydrangea by the shed' => ['Draft to validate'],
                'The mystery shrub' => ['Care sheet in progress'],
            ],
            array_merge(...$crawler->filter('.plant-tile')->each(static fn ($tile): array => [$tile->filter('strong')->text() => $tile->filter('.tag')->each(static fn ($tag): string => $tag->text())])),
        );
        self::assertSelectorTextSame('.nav a.active', 'My garden');
        self::assertSelectorTextSame('h1', 'My garden');
        self::assertSelectorNotExists('a[href="/collection"]');
    }

    public function testNoDraftSectionWhenEveryDraftHasItsPlanting(): void
    {
        PlantingFactory::new()->linkedTo(PlantFactory::new())->create(['name' => 'Hydrangea by the shed']);

        $this->client->request('GET', '/garden');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.drafts');
        self::assertSelectorTextContains('.plant-tile .tag-draft', 'Draft to validate');
    }

    public function testAPlantValidatedFromTheChatAsksWhereItGrows(): void
    {
        self::mockTime('2026-07-01 10:00');
        $planting = PlantingFactory::new()->of(PlantFactory::new()->validated()->create(['careSheet' => CareSheetFactory::new(['exposure' => Exposure::Sun])]))->create();

        $crawler = $this->client->request('GET', '/garden/' . $planting->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(['Not in a space yet: choose one, for its exposure, its soil and its neighbours.'], $crawler->filter('.planting-place td')->each(static fn ($cell): string => $cell->text()));
        self::assertSame('/garden/' . $planting->getId() . '/edit', $crawler->filter('.planting-place td a')->attr('href'));
        self::assertSame(['None recorded', 'None recorded'], $crawler->filter('.planting-care td')->each(static fn ($cell): string => $cell->text()));
        self::assertSelectorTextContains('.recommendation-info', 'Tell us where it grows, with "Edit garden info": its care sheet asks for full sun.');
        self::assertSelectorNotExists('.recommendation-warning');
        self::assertSelectorExists('a[href="/garden/' . $planting->getId() . '/edit"]');
    }

    public function testThePageOfMyPlantLinksToItsSpecies(): void
    {
        $plant = PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning)->create(['careSheet' => CareSheetFactory::new(['minTemperatureC' => -15, 'exposure' => Exposure::PartShade])]);
        $planting = PlantingFactory::new()->of($plant)->create();

        $crawler = $this->client->request('GET', '/garden/' . $planting->getId());

        self::assertSelectorTextSame('.breadcrumb .page-label', 'My plant');
        self::assertSelectorTextSame('.breadcrumb a[href="/garden"]', 'My garden');
        self::assertSelectorTextSame('.species-summary .page-label', 'Species');
        self::assertSelectorTextContains('.species-summary-name', 'Bigleaf hydrangea');
        self::assertSelectorTextContains('.species-summary-name .latin', 'Hydrangea macrophylla');
        self::assertSame(['Hardy down to -15 °C', 'Part shade', 'High watering'], $crawler->filter('.species-facts li')->each(static fn ($fact): string => trim($fact->text())));
        self::assertSame(['Pruning'], $crawler->filter('.species-reminders .tag')->each(static fn ($tag): string => trim($tag->text())), 'Read only.');
        self::assertSelectorExists('.species-summary a.button[href="/plants/' . $plant->getId() . '"]');
        self::assertSelectorNotExists('.plant-card, form[action*="/reminders/"]', 'The card and its switches belong to the page of the species.');
    }

    public function testTheNavigation(): void
    {
        $crawler = $this->client->request('GET', '/garden');

        // The badge of the notifications aside
        self::assertSame(['Assistant', 'My garden', 'Species', 'My spaces', 'Notifications'], $crawler->filter('.nav a')->each(static fn ($link): string => trim(substr($link->text(), 0, \strlen($link->text()) - \strlen($link->filter('.badge')->count() > 0 ? $link->filter('.badge')->text() : '')))));
        self::assertSame('/plants', $crawler->filter('.nav a')->eq(2)->attr('href'));
    }

    public function testTheSpeciesHaveTheirTabAndMyPlantsTheirs(): void
    {
        $plant = PlantFactory::new()->validated()->create();
        $planting = PlantingFactory::new()->of($plant)->create();

        foreach (['/plants', '/plants/new', '/plants/' . $plant->getId(), '/plants/' . $plant->getId() . '/edit'] as $url) {
            $this->client->request('GET', $url);
            self::assertSelectorTextSame('.nav a.active', 'Species', $url);
        }
        foreach (['/garden', '/garden/new', '/garden/new?plant=' . $plant->getId(), '/garden/' . $planting->getId(), '/garden/' . $planting->getId() . '/edit'] as $url) {
            $this->client->request('GET', $url);
            self::assertSelectorTextSame('.nav a.active', 'My garden', $url);
        }
    }

    public function testAddAPlantOfAValidatedSpeciesFromItsPage(): void
    {
        self::mockTime('2026-11-21 10:00');
        $plant = PlantFactory::new()->validated()->create(['commonName' => 'Panicled hydrangea', 'scientificName' => 'Hydrangea paniculata', 'trefleId' => 191052]);
        $northBed = SpaceFactory::createOne(['name' => 'North bed', 'exposure' => Exposure::PartShade, 'soil' => Soil::Clay]);
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId());

        $crawler = $this->client->click($crawler->filter('#in-your-garden')->selectLink('Add to my garden')->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.breadcrumb', 'All species › Hydrangea paniculata › Add to my garden');
        self::assertSelectorTextSame('.garden-species-chosen em', 'Hydrangea paniculata', 'The species is fixed.');
        self::assertSelectorNotExists('[data-controller~="live"], input[name="planting[trefleId]"], input[name="planting[scientificName]"]', 'No species to pick.');
        $form = $crawler->selectButton('Add to my garden')->form();
        self::assertSame('Panicled hydrangea', $form['planting[name]']->getValue());
        $form['planting[name]'] = 'Hydrangea by the shed';
        $form['planting[space]']->select((string) $northBed->getId());
        $this->client->submit($form);

        $planting = $this->lastPlanting();
        self::assertResponseRedirects('/garden/' . $planting->getId(), 303);
        self::assertSame('Hydrangea by the shed', $planting->getName());
        self::assertSame('North bed', $planting->getSpace()?->getName());
        self::assertSame($plant->getId(), $planting->getPlant()?->getId(), 'It follows the care sheet of the species.');
        self::assertSame('Hydrangea paniculata', $planting->getScientificName());
        self::assertSame(191052, $planting->getTrefleId());
        self::assertSame([], $this->draftMessages(), 'No agent: the care sheet is there.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Hydrangea by the shed is in your garden, with the care sheet of Panicled hydrangea.');
    }

    public function testTheSpeciesOfANewPlantCannotBeChangedOnceFixed(): void
    {
        $plant = PlantFactory::new()->validated()->create();
        $crawler = $this->client->request('GET', '/garden/new?plant=' . $plant->getId());
        $form = $crawler->selectButton('Add to my garden')->form();

        // As a request crafted by hand, with the fields of the picker
        $values = $form->getPhpValues();
        $values['planting']['trefleId'] = '263311';
        $values['planting']['scientificName'] = 'Rosa canina';
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422, 'Not fields of this form.');
        self::assertSame(0, self::getContainer()->get(PlantingRepository::class)->count());
    }

    #[TestWith(['draft'])]
    #[TestWith(['999999'])]
    #[TestWith(['abc'])]
    public function testOnlyAValidatedSpeciesIsFixed(string $plant): void
    {
        $draft = PlantFactory::createOne();

        $this->client->request('GET', '/garden/new?plant=' . ('draft' === $plant ? $draft->getId() : $plant));

        self::assertResponseStatusCodeSame(404);
    }

    public function testThePageOfAPlantingShowsItsSpaceAndItsNeighbours(): void
    {
        $northBed = SpaceFactory::createOne(['name' => 'North bed', 'exposure' => Exposure::Shade, 'soil' => Soil::Clay]);
        $hosta = PlantingFactory::new()->inSpace($northBed)->create(['name' => 'Hosta']);
        $fern = PlantingFactory::new()->inSpace($northBed)->create(['name' => 'Fern']);

        $crawler = $this->client->request('GET', '/garden/' . $hosta->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Space' => 'North bed', 'Exposure' => 'Shade', 'Soil' => 'Clay', 'Neighbours' => 'Fern'],
            array_merge(...$crawler->filter('.planting-place tr')->each(static fn ($row): array => [$row->filter('th')->text() => $row->filter('td')->text()])),
        );
        self::assertSame('/spaces/' . $northBed->getId(), $crawler->filter('.planting-place td a')->eq(0)->attr('href'));
        self::assertSame('/garden/' . $fern->getId(), $crawler->filter('.planting-place td a')->eq(1)->attr('href'));
    }

    public function testTheSpeciesOfMyPlantFacesTheExposureOfItsSpace(): void
    {
        $northBed = SpaceFactory::createOne(['name' => 'North bed', 'exposure' => Exposure::Shade]);
        $planting = PlantingFactory::new()->of(PlantFactory::new()->validated()->create(['careSheet' => CareSheetFactory::new(['exposure' => Exposure::PartShade])]))->inSpace($northBed)->create();

        $crawler = $this->client->request('GET', '/garden/' . $planting->getId());

        self::assertResponseIsSuccessful();
        self::assertSame('Shade', $crawler->filter('.planting-place tr')->eq(1)->filter('td')->text(), 'The exposure of its space.');
        self::assertContains('Part shade', $crawler->filter('.species-facts li')->each(static fn ($fact): string => trim($fact->text())), 'The exposure of its species.');
        self::assertSelectorTextContains('.recommendation-info', 'It prefers part shade and gets shade here: it should cope, but would do better in part shade.');
    }

    public function testEditTheGardenInfoOfAPlanting(): void
    {
        self::mockTime('2026-11-21 10:00');
        $balcony = SpaceFactory::createOne(['name' => 'Balcony', 'exposure' => Exposure::Sun]);
        $northBed = SpaceFactory::createOne(['name' => 'North bed', 'exposure' => Exposure::PartShade, 'soil' => Soil::Clay]);
        $lavender = PlantingFactory::new()->inSpace($balcony)->create(['name' => 'Lavender']);
        PlantingFactory::new()->inSpace($northBed)->create(['name' => 'Sage']);
        $hydrangea = PlantingFactory::new()
            ->of(PlantFactory::new()->validated()->create())
            ->inSpace($balcony)
            ->withCareRecord(CareTask::Watering, new \DateTimeImmutable('2026-11-10'))
            ->create()
        ;

        $crawler = $this->client->request('GET', '/garden/' . $hydrangea->getId());
        $crawler = $this->client->click($crawler->selectLink('Edit garden info')->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.breadcrumb', 'My garden › Bigleaf hydrangea › Edit garden info');
        self::assertSelectorNotExists('[name="planting[trefleId]"], [name="planting[scientificName]"]', 'The species stays.');
        self::assertSame(['Not in a space yet', 'Balcony · Full sun', 'North bed · Part shade, clay'], $crawler->filter('[name="planting[space]"] option')->each(static fn ($option): string => $option->text()));
        self::assertSame('Balcony · Full sun', $crawler->filter('[name="planting[space]"] option[selected]')->text());
        self::assertSelectorExists('a[href="/spaces/new"]');
        self::assertSame('2026-11-10', $crawler->filter('[name="planting[careRecords][watering]"]')->attr('value'));

        $form = $crawler->selectButton('Save')->form();
        $form['planting[name]'] = 'Hydrangea by the shed';
        $form['planting[photo]']->upload($this->file('shed.png', self::PNG));
        $form['planting[space]']->select((string) $northBed->getId());
        $form['planting[diseases]'] = 'Powdery mildew.';
        $form['planting[careRecords][pruning]'] = '2026-11-15';
        $this->client->submit($form);

        self::assertResponseRedirects('/garden/' . $hydrangea->getId());
        $hydrangea = $this->reload($hydrangea);
        self::assertSame('Hydrangea by the shed', $hydrangea->getName());
        self::assertSame('Hydrangea macrophylla', $hydrangea->getScientificName());
        self::assertSame('North bed', $hydrangea->getSpace()?->getName());
        self::assertSame('Powdery mildew.', $hydrangea->getDiseases());
        self::assertSame(
            [['watering', '2026-11-10'], ['pruning', '2026-11-15']],
            array_map(static fn ($record): array => [$record->task->value, $record->doneOn->format('Y-m-d')], $hydrangea->getCareRecords()),
            'The known date is not recorded twice.',
        );
        self::assertSame(['Sage'], array_map(static fn (Planting $neighbour): string => $neighbour->getName(), $hydrangea->getNeighbours()));
        self::assertSame([], $this->reload($lavender)->getNeighbours(), 'No longer its neighbour.');
        $firstPhoto = $this->storedPhoto($hydrangea);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'The garden info of Hydrangea by the shed is saved.');

        // A second photo replaces the first one; without one, the photo stays
        $crawler = $this->client->request('GET', '/garden/' . $hydrangea->getId() . '/edit');
        $form = $crawler->selectButton('Save')->form();
        $form['planting[photo]']->upload($this->file('shed-again.png', self::PNG));
        $this->client->submit($form);
        $secondPhoto = $this->storedPhoto($this->reload($hydrangea));
        self::assertFileDoesNotExist($firstPhoto);

        $crawler = $this->client->request('GET', '/garden/' . $hydrangea->getId() . '/edit');
        $this->client->submit($crawler->selectButton('Save')->form());
        self::assertSame($secondPhoto, $this->storedPhoto($this->reload($hydrangea)));
        self::assertFileExists($secondPhoto);
    }

    public function testTheEditOfAPlantingIsValidated(): void
    {
        $planting = PlantingFactory::createOne(['name' => 'Rose']);
        $crawler = $this->client->request('GET', '/garden/' . $planting->getId() . '/edit');

        $this->client->submit($crawler->selectButton('Save')->form(), ['planting[name]' => ' ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Rose', $this->reload($planting)->getName());
    }

    #[TestWith(['/garden/999999'])]
    #[TestWith(['/garden/999999/edit'])]
    #[TestWith(['/garden/999999/drafting'])]
    public function testAnUnknownPlantingIsNotFound(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function testWithoutForecastTheFrostAdviceReliesOnTheHardiness(): void
    {
        UnavailableDataSources::openMeteo(self::getContainer());
        self::mockTime('2026-12-01 10:00');
        $planting = PlantingFactory::new()->of(PlantFactory::new()->validated()->create(['careSheet' => CareSheetFactory::new(['minTemperatureC' => -15])]))->create();

        $this->client->request('GET', '/garden/' . $planting->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.recommendation-ok', 'Hardy down to -15 °C.');
    }

    public function testThePollOfAPlantingNotDrafting(): void
    {
        $planting = PlantingFactory::createOne(['name' => 'Rose']);

        $this->client->request('GET', '/garden/' . $planting->getId() . '/drafting');

        self::assertResponseIsSuccessful();
        self::assertSame('{"drafting":false}', $this->client->getResponse()->getContent());
    }

    public function testARetryOfAPlantingLinkedMeanwhileDraftsNothing(): void
    {
        $planting = PlantingFactory::new()->draftingFailed()->create(['name' => 'The mystery shrub', 'createdAt' => new \DateTimeImmutable('2026-03-01')]);
        $crawler = $this->client->request('GET', '/garden/' . $planting->getId());
        $form = $crawler->selectButton('Retry')->form();
        // Linked in another tab
        $plant = PlantFactory::new()->validated()->create();
        $planting = $this->reload($planting);
        $planting->linkTo(self::getContainer()->get(EntityManagerInterface::class)->getReference(Plant::class, $plant->getId()));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->submit($form);

        self::assertResponseRedirects('/garden/' . $planting->getId());
        self::assertSame([], $this->draftMessages());
        self::assertSame($plant->getId(), $this->reload($planting)->getPlant()?->getId());
    }

    public function testRetryAndLinkRequireAValidCsrfToken(): void
    {
        $planting = PlantingFactory::new()->draftingFailed()->create(['name' => 'Hydrangea by the shed', 'createdAt' => new \DateTimeImmutable('2026-03-01'), 'scientificName' => 'Hydrangea macrophylla']);
        $plant = PlantFactory::new()->validated()->create();

        $this->client->request('POST', '/garden/' . $planting->getId() . '/draft', ['_token' => 'forged']);
        self::assertResponseRedirects('/login');

        $this->client->request('POST', '/garden/' . $planting->getId() . '/plant', ['_token' => 'forged', 'plant' => (string) $plant->getId()]);
        self::assertResponseRedirects('/login');

        self::assertSame([], $this->draftMessages());
        $planting = $this->reload($planting);
        self::assertNull($planting->getPlant());
        self::assertTrue($planting->hasDraftingFailed());
    }

    private function storedPhoto(Planting $planting): string
    {
        $path = self::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/plantings/' . ($planting->getPhotoFilename() ?? self::fail('The photo is stored.'));
        $this->storedPhotos[] = $path;

        return $path;
    }

    private function file(string $clientName, string $base64Content): string
    {
        $path = $this->tmpDir . '/' . basename($clientName);
        file_put_contents($path, base64_decode($base64Content, true));

        return $path;
    }

    /**
     * @return list<array{int, string}> the planting and the conversation of each DraftCareSheet dispatched
     */
    private function draftMessages(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof DraftCareSheet) {
                $messages[] = [$message->plantingId, $message->conversationId];
            }
        }

        return $messages;
    }

    private function lastPlanting(): Planting
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(PlantingRepository::class)->findOneBy([], ['id' => 'DESC']) ?? self::fail('No planting was saved.');
    }

    /**
     * The agent looks at the collection, then ends without a draft: what the worker does, in process.
     */
    private function draftWithoutResult(): Planting
    {
        $planting = PlantingFactory::new()->drafting()->create(['name' => ScriptedGardener::NARRATED, 'createdAt' => new \DateTimeImmutable('2026-03-01')]);

        self::getContainer()->get(DraftCareSheetHandler::class)(new DraftCareSheet($planting->getId(), $planting->getConversationId() ?? self::fail(), 'en'));

        return $planting;
    }

    private function reload(Planting $planting): Planting
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(PlantingRepository::class)->find($planting->getId()) ?? self::fail('The planting is gone.');
    }
}
