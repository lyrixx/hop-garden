<?php

namespace App\Tests\Functional\Controller;

use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Factory\SpaceFactory;
use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Garden\PlantingRepository;
use App\Plant\Entity\Plant;
use App\Plant\Form\Model\CareSheetFormData;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\Confidence;
use App\Plant\Model\Exposure;
use App\Plant\Model\Source;
use App\Plant\PlantRepository;
use App\Reminder\Model\ReminderType;
use App\Tests\Utils\Http\UnavailableDataSources;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class PlantControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testTheDraftCardHighlightsTheValuesToVerify(): void
    {
        $plant = PlantFactory::createOne();

        $crawler = $this->client->request('GET', '/plants/' . $plant->getId() . '/card');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.status', 'Draft');
        self::assertSame(['Hardiness'], $crawler->filter('tr.needs-review th')->each(static fn ($th): string => $th->text()));
        self::assertSelectorExists('form[action$="/validate"] button');
    }

    public function testValidate(): void
    {
        $plant = PlantFactory::createOne();
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId());

        $this->client->submit($crawler->filter('form[action$="/validate"]')->form());

        self::assertResponseRedirects('/plants/' . $plant->getId(), 303);
        self::assertTrue($this->reload($plant)->isValidated());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Bigleaf hydrangea is validated. Pick its reminders below.');
        self::assertSelectorTextSame('.plant-card .status', 'Validated');
        self::assertSame([], $this->plantingsOf($plant), 'Validating adds no plant to the garden.');
        self::assertSelectorTextContains('.species-plantings .hint', 'None of your plants follows this care sheet.');
    }

    public function testValidatingADraftOfTheChatAddsNoPlant(): void
    {
        self::mockTime('2026-11-21 10:00');
        $plant = PlantFactory::createOne(['commonName' => 'Panicled hydrangea', 'scientificName' => 'Hydrangea paniculata', 'createdAt' => new \DateTimeImmutable('2026-11-21 09:55'), 'trefleId' => 191052]);
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId());

        $this->client->submit($crawler->filter('form[action$="/validate"]')->form());

        self::assertSame('2026-11-21 10:00', $this->reload($plant)->getValidatedAt()?->format('Y-m-d H:i'));
        self::assertSame([], $this->plantingsOf($plant), 'The "Add to my garden" card adds it, or the garden form.');
    }

    public function testValidatingTheDraftOfAPlantingPlantsNothingMore(): void
    {
        $plant = PlantFactory::createOne();
        PlantingFactory::new()->linkedTo($plant)->create(['name' => 'Hydrangea by the shed', 'trefleId' => 191047, 'scientificName' => 'Hydrangea macrophylla']);
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId() . '/card');

        $this->client->submit($crawler->filter('form[action$="/validate"]')->form(), [], ['HTTP_TURBO_FRAME' => 'plant-card-' . $plant->getId() . '-frame']);

        self::assertTrue($this->reload($plant)->isValidated());
        self::assertSame(['Hydrangea by the shed'], array_map(static fn (Planting $planting): string => $planting->getName(), $this->plantingsOf($plant)));
    }

    public function testTheCollectionMovedToTheGarden(): void
    {
        $this->client->request('GET', '/collection');

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/garden');
    }

    public function testTheCardIsValidatedInPlaceWherever(): void
    {
        $plant = PlantFactory::createOne();
        $frame = 'plant-card-' . $plant->getId() . '-frame';

        foreach (['/plants/' . $plant->getId() . '/card', '/plants/' . $plant->getId()] as $url) {
            $this->client->request('GET', $url);
            self::assertSelectorExists('turbo-frame#' . $frame . ':not([target]) form[action$="/validate"]:not([data-turbo-frame])', 'In the chat as on the page of the species.');
            self::assertSelectorExists('turbo-frame#' . $frame . ' .card-head h3 a[data-turbo-frame="_top"]', 'Only the links that navigate leave the frame.');
        }
    }

    public function testValidateFromTheChatReturnsTheUpdatedCardInItsFrame(): void
    {
        $plant = PlantFactory::createOne();
        $frame = 'plant-card-' . $plant->getId() . '-frame';
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId() . '/card');

        // As Turbo submits a form of a frame
        $this->client->submit($crawler->filter('form[action$="/validate"]')->form(), [], ['HTTP_TURBO_FRAME' => $frame]);

        self::assertResponseRedirects('/plants/' . $plant->getId() . '/card', 303);
        $this->client->followRedirect();
        self::assertSelectorExists('turbo-frame#' . $frame . ' article.plant-card.is-validated');
        self::assertSelectorNotExists('form[action$="/validate"]');
        self::assertSame([], $this->plantingsOf($plant), 'Validated in the chat: the agent asks where it grows, then proposes to add it.');
        self::assertSelectorExists('turbo-frame#' . $frame . ' article.plant-card[data-chat-notice="I validated the care sheet of Bigleaf hydrangea."]', 'What the chat tells the agent.');
        foreach (ReminderType::cases() as $type) {
            self::assertSelectorExists('turbo-frame#plant-' . $plant->getId() . '-reminder-' . $type->value . ' form.plate-reminder button.toggle', 'Its reminders, there in the chat.');
        }
        self::assertSelectorNotExists('a.button-next', 'No plant of the garden to see yet.');

        $this->client->request('GET', '/garden');
        self::assertSelectorNotExists('.flash', 'No message for later: the card said it.');
    }

    public function testValidateRequiresAValidCsrfToken(): void
    {
        $plant = PlantFactory::createOne();

        $this->client->request('POST', '/plants/' . $plant->getId() . '/validate', ['_token' => 'forged']);

        // The security layer turns an invalid token into a 401
        self::assertResponseStatusCodeSame(401);
        self::assertFalse($this->reload($plant)->isValidated());
    }

    public function testValidatingTwiceIsHarmless(): void
    {
        $plant = PlantFactory::createOne();
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId());
        $form = $crawler->filter('form[action$="/validate"]')->form();

        // Validated meanwhile, in another tab
        $this->client->submit($form);
        $this->client->submit($form);

        self::assertResponseRedirects('/plants/' . $plant->getId());
        self::assertTrue($this->reload($plant)->isValidated());
    }

    public function testEditingADraftMarksTheChangedValuesAsVerified(): void
    {
        $plant = PlantFactory::createOne();
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId() . '/edit');

        $this->client->submit($crawler->selectButton('Save the draft')->form(), [
            'care_sheet[minTemperatureC]' => '-20',
        ]);

        self::assertResponseRedirects('/plants/' . $plant->getId());
        $careSheet = $this->reload($plant)->getCareSheet();
        self::assertSame(-20, $careSheet->getMinTemperatureC());
        self::assertSame(Confidence::High, $careSheet->getConfidenceOf(CareSheetField::MinTemperatureC));
        self::assertContains(CareSheetFormData::USER_SOURCE, array_map(static fn ($source) => $source->name, $careSheet->getSources()));
        self::assertFalse($this->reload($plant)->isValidated(), 'Editing is not validating.');
    }

    public function testTheCardOfAValidatedPlantLinksToItsEdition(): void
    {
        $plant = PlantFactory::new()->validated()->create();

        $this->client->request('GET', '/plants/' . $plant->getId() . '/card');
        self::assertSelectorExists('a[href$="/plants/' . $plant->getId() . '/edit"]');

        $this->client->request('GET', '/plants/' . $plant->getId());
        self::assertSelectorExists('a[href$="/plants/' . $plant->getId() . '/edit"]');
    }

    public function testEditingAValidatedPlantKeepsItValidated(): void
    {
        $plant = PlantFactory::new()->validated()->create();
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId() . '/edit');

        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Save')->form(), [
            'care_sheet[minTemperatureC]' => '-20',
        ]);

        self::assertResponseRedirects('/plants/' . $plant->getId());
        $plant = $this->reload($plant);
        $careSheet = $plant->getCareSheet();
        self::assertSame(-20, $careSheet->getMinTemperatureC());
        self::assertSame(Confidence::High, $careSheet->getConfidenceOf(CareSheetField::MinTemperatureC));
        self::assertContains(CareSheetFormData::USER_SOURCE, array_map(static fn ($source) => $source->name, $careSheet->getSources()));
        self::assertTrue($plant->isValidated());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Care sheet updated.');
    }

    public function testRemindersOfAValidatedPlant(): void
    {
        $plant = PlantFactory::new()->validated()->create();
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId());

        $this->client->submit($crawler->filter('form[action$="/reminders/frost"]')->form(), [], ['HTTP_TURBO_FRAME' => 'plant-' . $plant->getId() . '-reminder-frost']);

        self::assertResponseRedirects('/plants/' . $plant->getId() . '/card', 303);
        self::assertSame([ReminderType::Frost], $this->reload($plant)->getEnabledReminderTypes());

        // Turbo takes the frame of the switch from the card
        $this->client->followRedirect();
        self::assertSelectorExists('turbo-frame#plant-' . $plant->getId() . '-reminder-frost form.plate-reminder.is-enabled button.toggle.is-on[aria-checked="true"]');
        self::assertSelectorExists('turbo-frame#plant-' . $plant->getId() . '-reminder-pruning form.plate-reminder:not(.is-enabled)');
    }

    public function testAReminderSwitchedInTheChatAnswersInItsFrame(): void
    {
        $plant = PlantFactory::new()->validated()->create();
        $frame = 'plant-' . $plant->getId() . '-reminder-pruning';
        // As the chat loads the card
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId() . '/card');

        $this->client->submit($crawler->filter('turbo-frame#' . $frame . ' form')->form(), [], ['HTTP_TURBO_FRAME' => $frame]);

        self::assertResponseRedirects('/plants/' . $plant->getId() . '/card', 303);
        self::assertSame([ReminderType::Pruning], $this->reload($plant)->getEnabledReminderTypes());
        $this->client->followRedirect();
        self::assertSelectorExists('turbo-frame#' . $frame . ' form.plate-reminder.is-enabled button.toggle[aria-checked="true"]');
    }

    public function testEditInTheFrameOfTheCard(): void
    {
        $plant = PlantFactory::createOne();
        $frame = 'plant-card-' . $plant->getId() . '-frame';
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId() . '/edit', server: ['HTTP_TURBO_FRAME' => $frame]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#' . $frame . ' form.care-sheet-form[action="/plants/' . $plant->getId() . '/edit"]', 'Its action says where it goes, whatever the page around the frame.');
        self::assertSelectorExists('turbo-frame#' . $frame . ' a.button[href="/plants/' . $plant->getId() . '/card"]', 'Cancel brings the card back.');
        self::assertSelectorNotExists('.topbar', 'The frame only, not a page.');

        $this->client->submit($crawler->selectButton('Save the draft')->form(), ['care_sheet[minTemperatureC]' => '-20'], ['HTTP_TURBO_FRAME' => $frame]);

        self::assertResponseRedirects('/plants/' . $plant->getId() . '/card', 303);
        self::assertSame(-20, $this->reload($plant)->getCareSheet()->getMinTemperatureC());
        $this->client->followRedirect();
        self::assertSelectorExists('turbo-frame#' . $frame . ' article.plant-card.is-draft');
        self::assertSelectorTextContains('turbo-frame#' . $frame . ' .hardiness-coldest', '−20 °C');

        $this->client->request('GET', '/garden');
        self::assertSelectorNotExists('.flash', 'No message for later: the card said it.');
    }

    public function testAnInvalidCareSheetComesBackInTheFrame(): void
    {
        $plant = PlantFactory::new()->validated()->create();
        $frame = 'plant-card-' . $plant->getId() . '-frame';
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId() . '/edit', server: ['HTTP_TURBO_FRAME' => $frame]);

        $this->client->submit($crawler->selectButton('Save')->form()->disableValidation(), ['care_sheet[minTemperatureC]' => '-61'], ['HTTP_TURBO_FRAME' => $frame]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('turbo-frame#' . $frame . ' form.care-sheet-form');
        self::assertSelectorNotExists('.topbar');
        self::assertSame(-15, $this->reload($plant)->getCareSheet()->getMinTemperatureC());
    }

    public function testTheIndexListsEverySpeciesValidatedFirstWithMyPlants(): void
    {
        $hydrangea = PlantFactory::new()->validated()->create();
        PlantFactory::new()->validated()->create(['commonName' => 'Panicled hydrangea']);
        $rose = PlantFactory::createOne(['commonName' => 'Climbing rose', 'scientificName' => 'Rosa canina']);
        $northBed = SpaceFactory::createOne(['name' => 'North bed', 'exposure' => Exposure::Shade]);
        $shed = PlantingFactory::new()->inSpace($northBed)->linkedTo($hydrangea)->create(['name' => 'Hydrangea by the shed']);
        $gate = PlantingFactory::new()->linkedTo($hydrangea)->create(['name' => 'Hydrangea by the gate', 'createdAt' => new \DateTimeImmutable('2026-11-02')]);

        $crawler = $this->client->request('GET', '/plants');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.nav a.active', 'Species');
        self::assertSelectorTextSame('.breadcrumb .page-label-species', 'Species');
        self::assertSelectorTextSame('h1', 'Species');
        self::assertSame(
            ['species-validated' => ['Bigleaf hydrangea', 'Panicled hydrangea'], 'species-drafts' => ['Climbing rose']],
            array_merge(...$crawler->filter('.species-index-section')->each(static fn ($section): array => [(string) $section->attr('id') => $section->filter('.species-row-name strong')->each(static fn ($name): string => $name->text())])),
            'The validated species by name, then the drafts.',
        );
        $bigleaf = $crawler->filter('.species-row')->eq(0);
        self::assertSame('/plants/' . $hydrangea->getId(), $bigleaf->filter('a.species-row-name')->attr('href'));
        self::assertSame('Hydrangea macrophylla', $bigleaf->filter('.latin')->text());
        self::assertCount(1, $bigleaf->filter('img.species-summary-plate[src$=".webp"]'), 'The plate is the species.');
        self::assertSame('Care sheet validated', trim($bigleaf->filter('.status')->text()));
        self::assertSame('2 plants in your garden', $bigleaf->filter('.species-row-plantings .kicker')->text());
        self::assertSame(
            ['/garden/' . $gate->getId() => 'Hydrangea by the gate', '/garden/' . $shed->getId() => 'Hydrangea by the shed'],
            array_merge(...$bigleaf->filter('a.species-planting')->each(static fn ($link): array => [(string) $link->attr('href') => $link->filter('strong')->text()])),
        );
        self::assertSame(['/spaces/' . $northBed->getId() => 'North bed'], array_merge(...$bigleaf->filter('a.species-planting-space')->each(static fn ($link): array => [(string) $link->attr('href') => trim($link->text())])));
        self::assertSame('None of your plants follows it.', $crawler->filter('.species-row')->eq(1)->filter('.species-row-plantings .hint')->text());
        self::assertSame('Draft to review', trim($crawler->filter('#species-drafts .species-row[class*="is-draft"] .status')->text()));
        self::assertSame('/plants/' . $rose->getId(), $crawler->filter('#species-drafts a.species-row-name')->attr('href'));
        self::assertSelectorExists('a.button[href="/plants/new"]');
    }

    public function testTheIndexTakesAsManyQueriesForOneSpeciesAsForMany(): void
    {
        self::withPlantings(PlantFactory::new()->validated()->create(), 1);
        $queriesForOne = $this->indexQueryCount();

        self::withPlantings(PlantFactory::new()->validated()->create(['commonName' => 'Panicled hydrangea']), 3);
        self::withPlantings(PlantFactory::createOne(['commonName' => 'Climbing rose']), 2);
        PlantFactory::createOne(['commonName' => 'Lavender']);

        self::assertSame($queriesForOne, $this->indexQueryCount(), 'No query per species, nor per plant.');
    }

    public function testTheIndexWithoutSpecies(): void
    {
        $this->client->request('GET', '/plants');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('p.callout', 'No species yet.');
        self::assertSelectorNotExists('.species-index-section');
    }

    public function testCreateASpeciesByHandThenValidateIt(): void
    {
        self::mockTime('2026-11-21 10:00');
        $crawler = $this->client->request('GET', '/plants');
        $crawler = $this->client->click($crawler->selectLink('New species')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.nav a.active', 'Species');
        self::assertSelectorTextContains('.breadcrumb', 'All species › New species');

        $form = $crawler->selectButton('Create the draft')->form();
        $form['new_plant[name]'] = 'hortensia';
        // Set by the species picker
        $form['new_plant[trefleId]'] = '191052';
        $form['new_plant[scientificName]'] = 'Forged by the browser';
        $this->client->submit($form);

        $plant = $this->lastPlant();
        self::assertResponseRedirects('/plants/' . $plant->getId() . '/edit', 303);
        self::assertFalse($plant->isValidated());
        self::assertSame('Panicled hydrangea', $plant->getCommonName(), 'The names of Trefle.');
        self::assertSame('Hydrangea paniculata', $plant->getScientificName(), 'From Trefle, not from the browser.');
        self::assertSame('Hydrangeaceae', $plant->getFamily());
        self::assertSame(191052, $plant->getTrefleId());
        self::assertSame('2026-11-21 10:00', $plant->getCreatedAt()->format('Y-m-d H:i'));
        $careSheet = $plant->getCareSheet();
        self::assertSame(array_fill_keys(CareSheetField::values(), Confidence::Low), $careSheet->getConfidences(), 'Nothing known yet: every value to fill in.');
        self::assertSame([Source::TREFLE], array_map(static fn (Source $source): string => $source->name, $careSheet->getSources()));
        self::assertSame([], $this->plantingsOf($plant), 'Not in the garden before it is validated.');

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'The draft of Panicled hydrangea is ready: fill in its care sheet, then validate it.');
        $this->client->submit($crawler->selectButton('Save the draft')->form(), ['care_sheet[minTemperatureC]' => '-25']);
        self::assertResponseRedirects('/plants/' . $plant->getId(), 303);
        self::assertSame(Confidence::High, $this->reload($plant)->getCareSheet()->getConfidenceOf(CareSheetField::MinTemperatureC));
        self::assertSame(Confidence::Low, $this->reload($plant)->getCareSheet()->getConfidenceOf(CareSheetField::Watering), 'Left as it was: still to verify.');

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextSame('.card-foot .review-note', 'Fill in the highlighted values, then validate. A value left as is stays highlighted, even when it is right.');
        $this->client->submit($crawler->filter('form[action$="/validate"]')->form());

        self::assertTrue($this->reload($plant)->isValidated());
        self::assertSame([], $this->plantingsOf($plant), 'Validating adds no plant to the garden.');
        $crawler = $this->client->followRedirect();
        self::assertSame('/garden/new?plant=' . $plant->getId(), $crawler->filter('.species-plantings-head')->selectLink('Add to my garden')->attr('href'), 'The garden form adds it.');
    }

    /**
     * @param array<string, string> $values
     */
    #[TestWith([['new_plant[name]' => 'hortensia'], 'Pick its species among the ones Trefle finds.'], 'none picked')]
    #[TestWith([['new_plant[name]' => 'lavender', 'new_plant[trefleId]' => '191052'], 'Pick its species again: Trefle does not find it for this name.'], 'not one of the name')]
    #[TestWith([['new_plant[name]' => ' ', 'new_plant[trefleId]' => '191052'], 'This value should not be blank.'], 'no name')]
    public function testASpeciesIsOneTrefleFindsForItsName(array $values, string $error): void
    {
        $crawler = $this->client->request('GET', '/plants/new');

        $this->client->submit($crawler->selectButton('Create the draft')->form()->disableValidation(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form.care-sheet-form', $error);
        self::assertNull($this->lastPlantOrNull(), 'Nothing created.');
    }

    public function testASpeciesIsNotCreatedWhileTrefleDoesNotAnswer(): void
    {
        // The unavailable client stays for both requests
        $this->client->disableReboot();
        UnavailableDataSources::trefle(self::getContainer());
        $crawler = $this->client->request('GET', '/plants/new');

        $this->client->submit($crawler->selectButton('Create the draft')->form(), ['new_plant[name]' => 'hortensia', 'new_plant[trefleId]' => '191052']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form.care-sheet-form', 'Trefle does not answer: try again in a moment.');
        self::assertNull($this->lastPlantOrNull());
    }

    #[TestWith([191052, 'Hydrangea paniculata'], 'by its Trefle id')]
    #[TestWith([null, 'hydrangea PANICULATA'], 'a draft of the chat, by its name')]
    public function testASpeciesWithACareSheetIsNotMadeTwice(?int $trefleId, string $scientificName): void
    {
        $existing = PlantFactory::createOne(['commonName' => 'Hortensia', 'scientificName' => $scientificName, 'trefleId' => $trefleId]);
        $crawler = $this->client->request('GET', '/plants/new');

        $this->client->submit($crawler->selectButton('Create the draft')->form(), ['new_plant[name]' => 'hortensia', 'new_plant[trefleId]' => '191052']);

        self::assertResponseRedirects('/plants/' . $existing->getId(), 303);
        self::assertSame($existing->getId(), $this->lastPlant()->getId(), 'No second care sheet.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', $scientificName . ' has its care sheet already: here it is.');
    }

    public function testTheNewSpeciesOffersTheAssistant(): void
    {
        $crawler = $this->client->request('GET', '/plants');
        self::assertSame('/assistant?message=' . rawurlencode('Draft the care sheet of a new species'), $crawler->selectLink('ask the assistant to draft it')->attr('href'));

        $crawler = $this->client->request('GET', '/plants/new');
        self::assertSame('/assistant?message=' . rawurlencode('Draft the care sheet of a new species'), $crawler->selectLink('Ask the assistant to draft it')->attr('href'));
    }

    public function testAddToMyGardenOnlyFromAValidatedSpecies(): void
    {
        $validated = PlantFactory::new()->validated()->create();
        $draft = PlantFactory::createOne(['commonName' => 'Draft hydrangea']);

        $crawler = $this->client->request('GET', '/plants/' . $validated->getId());
        self::assertSame('/garden/new?plant=' . $validated->getId(), $crawler->filter('#in-your-garden .species-plantings-head')->selectLink('Add to my garden')->attr('href'));

        $this->client->request('GET', '/plants/' . $draft->getId());
        self::assertSelectorNotExists('#in-your-garden a[href^="/garden/new"]', 'A draft is validated first.');
    }

    public function testThePageOfTheSpeciesListsMyPlantsOfIt(): void
    {
        $plant = PlantFactory::new()->validated()->create();
        $northBed = SpaceFactory::createOne(['name' => 'North bed']);
        $shed = PlantingFactory::new()->inSpace($northBed)->linkedTo($plant)->create(['name' => 'Hydrangea by the shed']);
        $gate = PlantingFactory::new()->linkedTo($plant)->create(['name' => 'Hydrangea by the gate', 'createdAt' => new \DateTimeImmutable('2026-11-02')]);

        $crawler = $this->client->request('GET', '/plants/' . $plant->getId());

        self::assertResponseIsSuccessful('The species keeps its page.');
        self::assertSame(
            ['/garden/' . $gate->getId() => 'Hydrangea by the gate', '/garden/' . $shed->getId() => 'Hydrangea by the shed'],
            array_merge(...$crawler->filter('.species-plantings a.species-planting')->each(static fn ($link): array => [(string) $link->attr('href') => $link->filter('strong')->text()])),
        );
        self::assertSame(
            [[], ['/spaces/' . $northBed->getId() => 'North bed']],
            $crawler->filter('.species-planting-list li')->each(static fn ($item): array => array_merge([], ...$item->filter('a.species-planting-space')->each(static fn ($link): array => [(string) $link->attr('href') => trim($link->text())]))),
            'The space of each plant, when it has one.',
        );
        self::assertSelectorTextContains('.species-plantings', 'They share this care sheet, and so its reminders.');
        self::assertSelectorExists('.plant-card a.button-next[href="/plants/' . $plant->getId() . '#in-your-garden"]', 'Its reminders are about both: my plants, listed here.');
    }

    #[TestWith(['/plants/999999'])]
    #[TestWith(['/plants/999999/card'])]
    #[TestWith(['/plants/999999/edit'])]
    public function testAnUnknownPlantIsNotFound(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, string> $values
     */
    #[TestWith([['care_sheet[commonName]' => ' ']])]
    #[TestWith([['care_sheet[minTemperatureC]' => '-61']])]
    #[TestWith([['care_sheet[minTemperatureC]' => '']])]
    #[TestWith([['care_sheet[exposure]' => '']])]
    #[TestWith([['care_sheet[watering]' => '']])]
    public function testAnInvalidCareSheetIsRefused(array $values): void
    {
        $plant = PlantFactory::new()->validated()->create();
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId() . '/edit');
        // As a request crafted by hand: the browser would refuse a blank field
        $form = $crawler->selectButton('Save')->form()->disableValidation();

        $this->client->submit($form, $values);

        self::assertResponseStatusCodeSame(422);
        $plant = $this->reload($plant);
        self::assertSame('Bigleaf hydrangea', $plant->getCommonName());
        self::assertSame(-15, $plant->getCareSheet()->getMinTemperatureC());
    }

    public function testDisableAReminder(): void
    {
        $plant = PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Frost, ReminderType::Pruning)->create();
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId());

        $this->client->submit($crawler->filter('form[action$="/reminders/frost"]')->form());

        self::assertResponseRedirects('/plants/' . $plant->getId(), 303);
        self::assertSame([ReminderType::Pruning], $this->reload($plant)->getEnabledReminderTypes());
    }

    public function testADraftHasNoReminder(): void
    {
        $draft = PlantFactory::createOne();
        $token = $this->plantToken();

        $this->client->request('POST', '/plants/' . $draft->getId() . '/reminders/frost', ['_token' => $token, 'enabled' => '1']);

        self::assertResponseRedirects('/plants/' . $draft->getId());
        self::assertSame([], $this->reload($draft)->getEnabledReminderTypes());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'Validate its care sheet first: reminders are set on a validated care sheet only.');
    }

    public function testAnUnknownReminderTypeIsNotFound(): void
    {
        $plant = PlantFactory::new()->validated()->create();
        $token = $this->plantToken();

        $this->client->request('POST', '/plants/' . $plant->getId() . '/reminders/watering', ['_token' => $token, 'enabled' => '1']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testRemindersRequireAValidCsrfToken(): void
    {
        $plant = PlantFactory::new()->validated()->create();

        $this->client->request('POST', '/plants/' . $plant->getId() . '/reminders/frost', ['_token' => 'forged', 'enabled' => '1']);

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->reload($plant)->getEnabledReminderTypes());
    }

    public function testValidatingAnUnknownPlant(): void
    {
        $token = $this->plantToken();

        $this->client->request('POST', '/plants/999999/validate', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The CSRF token of the plant forms, from a page that holds one.
     */
    private function plantToken(): string
    {
        $plant = PlantFactory::createOne(['commonName' => 'Token holder']);
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId() . '/card');

        return (string) $crawler->filter('form[action$="/validate"] input[name="_token"]')->attr('value');
    }

    /**
     * Each in a space of its own.
     */
    private static function withPlantings(Plant $plant, int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            PlantingFactory::new()
                ->inSpace(SpaceFactory::new(['name' => $plant->getCommonName() . ' bed ' . $i]))
                ->linkedTo($plant)
                ->create(['name' => $plant->getCommonName() . ' ' . $i])
            ;
        }
    }

    private function indexQueryCount(): int
    {
        // The next request reboots the kernel, as in production: nothing loaded already to hide a query, and the
        // queries of the fixtures not counted
        $this->client->request('GET', '/plants');
        $this->client->enableProfiler();

        $this->client->request('GET', '/plants');

        self::assertResponseIsSuccessful();
        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }

    private function lastPlant(): Plant
    {
        return $this->lastPlantOrNull() ?? self::fail('No plant was saved.');
    }

    private function lastPlantOrNull(): ?Plant
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(PlantRepository::class)->findOneBy([], ['id' => 'DESC']);
    }

    private function reload(Plant $plant): Plant
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(PlantRepository::class)->get($plant->getId());
    }

    /**
     * @return list<Planting>
     */
    private function plantingsOf(Plant $plant): array
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return array_values(self::getContainer()->get(PlantingRepository::class)->findBy(['plant' => $plant->getId()]));
    }
}
