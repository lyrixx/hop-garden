<?php

namespace App\Tests\Functional\Controller;

use App\Garden\Model\Planting;
use App\Garden\Model\Space;
use App\Garden\PlantingRepository;
use App\Plant\Form\Model\CareSheetFormData;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\Confidence;
use App\Plant\Model\Exposure;
use App\Plant\Model\Plant;
use App\Plant\PlantRepository;
use App\Reminder\Model\ReminderType;
use App\Tests\Factory\PlantFactory;
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
        $plant = $this->save(PlantFactory::draft());

        $crawler = $this->client->request('GET', '/plants/' . $plant->getId() . '/card');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.status', 'Draft');
        self::assertSame(['Hardiness'], $crawler->filter('tr.needs-review th')->each(static fn ($th): string => $th->text()));
        self::assertSelectorExists('form[action$="/validate"] button');
    }

    public function testValidate(): void
    {
        $plant = $this->save(PlantFactory::draft());
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId());

        $this->client->submit($crawler->filter('form[action$="/validate"]')->form());

        self::assertResponseRedirects('/plants/' . $plant->getId(), 303);
        self::assertTrue($this->reload($plant)->isValidated());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Bigleaf hydrangea is validated and in your garden');
        self::assertSelectorTextContains('.plant-card .status', 'In your garden');
        $plantings = $this->plantingsOf($plant);
        self::assertSelectorTextContains('.species-plantings a[href="/garden/' . $plantings[0]->getId() . '"]', 'Bigleaf hydrangea', 'My plant, in the garden.');
    }

    public function testValidatingADraftOfTheChatPutsThePlantInTheGarden(): void
    {
        self::mockTime('2026-11-21 10:00');
        $plant = $this->save(Plant::propose('Panicled hydrangea', 'Hydrangea paniculata', 'Hydrangeaceae', PlantFactory::careSheet(), new \DateTimeImmutable('2026-11-21 09:55'), 191052));
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId());

        $this->client->submit($crawler->filter('form[action$="/validate"]')->form());

        $plantings = $this->plantingsOf($plant);
        self::assertCount(1, $plantings);
        self::assertSame('Panicled hydrangea', $plantings[0]->getName());
        self::assertSame('Hydrangea paniculata', $plantings[0]->getScientificName());
        self::assertSame(191052, $plantings[0]->getTrefleId());
        self::assertSame('2026-11-21 10:00', $plantings[0]->getCreatedAt()->format('Y-m-d H:i'));
        self::assertNull($plantings[0]->getSpace(), 'Where it grows is up to the user.');
        self::assertNull($plantings[0]->getPhotoFilename());
    }

    public function testValidatingTheDraftOfAPlantingPlantsNothingMore(): void
    {
        $plant = $this->save(PlantFactory::draft());
        $planting = new Planting('Hydrangea by the shed', null, null, new \DateTimeImmutable('2026-11-01'), 191047, 'Hydrangea macrophylla');
        $planting->linkTo($plant);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($planting);
        $entityManager->flush();
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
        $plant = $this->save(PlantFactory::draft());
        $frame = 'plant-card-' . $plant->getId() . '-frame';

        foreach (['/plants/' . $plant->getId() . '/card', '/plants/' . $plant->getId()] as $url) {
            $this->client->request('GET', $url);
            self::assertSelectorExists('turbo-frame#' . $frame . ':not([target]) form[action$="/validate"]:not([data-turbo-frame])', 'In the chat as on the page of the species.');
            self::assertSelectorExists('turbo-frame#' . $frame . ' .card-head h3 a[data-turbo-frame="_top"]', 'Only the links that navigate leave the frame.');
        }
    }

    public function testValidateFromTheChatReturnsTheUpdatedCardInItsFrame(): void
    {
        $plant = $this->save(PlantFactory::draft());
        $frame = 'plant-card-' . $plant->getId() . '-frame';
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId() . '/card');

        // As Turbo submits a form of a frame
        $this->client->submit($crawler->filter('form[action$="/validate"]')->form(), [], ['HTTP_TURBO_FRAME' => $frame]);

        self::assertResponseRedirects('/plants/' . $plant->getId() . '/card', 303);
        $this->client->followRedirect();
        self::assertSelectorExists('turbo-frame#' . $frame . ' article.plant-card.is-validated');
        self::assertSelectorNotExists('form[action$="/validate"]');
        $plantings = $this->plantingsOf($plant);
        self::assertCount(1, $plantings, 'Validated in the chat: the plant joins the garden.');
        foreach (ReminderType::cases() as $type) {
            self::assertSelectorExists('turbo-frame#plant-' . $plant->getId() . '-reminder-' . $type->value . ' form.plate-reminder button.toggle', 'Its reminders, there in the chat.');
        }
        self::assertSelectorExists('a.button-next[href="/garden/' . $plantings[0]->getId() . '"][data-turbo-frame="_top"]');

        $this->client->request('GET', '/garden');
        self::assertSelectorNotExists('.flash', 'No message for later: the card said it.');
    }

    public function testValidateRequiresAValidCsrfToken(): void
    {
        $plant = $this->save(PlantFactory::draft());

        $this->client->request('POST', '/plants/' . $plant->getId() . '/validate', ['_token' => 'forged']);

        // The security layer turns an invalid token into a 401
        self::assertResponseStatusCodeSame(401);
        self::assertFalse($this->reload($plant)->isValidated());
    }

    public function testValidatingTwiceIsHarmless(): void
    {
        $plant = $this->save(PlantFactory::draft());
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId());
        $form = $crawler->filter('form[action$="/validate"]')->form();

        // Validated meanwhile, in another tab
        $this->client->submit($form);
        $this->client->submit($form);

        self::assertResponseRedirects('/plants/' . $plant->getId());
        self::assertTrue($this->reload($plant)->isValidated());
        self::assertCount(1, $this->plantingsOf($plant), 'Planted once.');
    }

    public function testEditingADraftMarksTheChangedValuesAsVerified(): void
    {
        $plant = $this->save(PlantFactory::draft());
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
        $plant = $this->save(PlantFactory::validated());

        $this->client->request('GET', '/plants/' . $plant->getId() . '/card');
        self::assertSelectorExists('a[href$="/plants/' . $plant->getId() . '/edit"]');

        $this->client->request('GET', '/plants/' . $plant->getId());
        self::assertSelectorExists('a[href$="/plants/' . $plant->getId() . '/edit"]');
    }

    public function testEditingAValidatedPlantKeepsItValidated(): void
    {
        $plant = $this->save(PlantFactory::validated());
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
        $plant = $this->save(PlantFactory::validated());
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
        $plant = $this->save(PlantFactory::validated());
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
        $plant = $this->save(PlantFactory::draft());
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
        $plant = $this->save(PlantFactory::validated());
        $frame = 'plant-card-' . $plant->getId() . '-frame';
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId() . '/edit', server: ['HTTP_TURBO_FRAME' => $frame]);

        $this->client->submit($crawler->selectButton('Save')->form()->disableValidation(), ['care_sheet[minTemperatureC]' => '-61'], ['HTTP_TURBO_FRAME' => $frame]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('turbo-frame#' . $frame . ' form.care-sheet-form');
        self::assertSelectorNotExists('.topbar');
        self::assertSame(-15, $this->reload($plant)->getCareSheet()->getMinTemperatureC());
    }

    public function testThePageOfTheSpeciesListsMyPlantsOfIt(): void
    {
        $plant = $this->save(PlantFactory::validated());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $northBed = new Space('North bed', Exposure::Shade, null, new \DateTimeImmutable('2026-11-01'));
        $entityManager->persist($northBed);
        $shed = new Planting('Hydrangea by the shed', $northBed, null, new \DateTimeImmutable('2026-11-01'));
        $shed->linkTo($plant);
        $gate = new Planting('Hydrangea by the gate', null, null, new \DateTimeImmutable('2026-11-02'));
        $gate->linkTo($plant);
        $entityManager->persist($shed);
        $entityManager->persist($gate);
        $entityManager->flush();

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
        $plant = $this->save(PlantFactory::validated());
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
        $plant = PlantFactory::validated();
        $plant->enableReminder(ReminderType::Frost);
        $plant->enableReminder(ReminderType::Pruning);
        $this->save($plant);
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId());

        $this->client->submit($crawler->filter('form[action$="/reminders/frost"]')->form());

        self::assertResponseRedirects('/plants/' . $plant->getId(), 303);
        self::assertSame([ReminderType::Pruning], $this->reload($plant)->getEnabledReminderTypes());
    }

    public function testADraftHasNoReminder(): void
    {
        $draft = $this->save(PlantFactory::draft());
        $token = $this->plantToken();

        $this->client->request('POST', '/plants/' . $draft->getId() . '/reminders/frost', ['_token' => $token, 'enabled' => '1']);

        self::assertResponseRedirects('/plants/' . $draft->getId());
        self::assertSame([], $this->reload($draft)->getEnabledReminderTypes());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'is still a draft: the user must validate it before reminders can be set.');
    }

    public function testAnUnknownReminderTypeIsNotFound(): void
    {
        $plant = $this->save(PlantFactory::validated());
        $token = $this->plantToken();

        $this->client->request('POST', '/plants/' . $plant->getId() . '/reminders/watering', ['_token' => $token, 'enabled' => '1']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testRemindersRequireAValidCsrfToken(): void
    {
        $plant = $this->save(PlantFactory::validated());

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
        $plant = $this->save(PlantFactory::draft('Token holder'));
        $crawler = $this->client->request('GET', '/plants/' . $plant->getId() . '/card');

        return (string) $crawler->filter('form[action$="/validate"] input[name="_token"]')->attr('value');
    }

    private function save(Plant $plant): Plant
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($plant);
        $entityManager->flush();

        return $plant;
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
