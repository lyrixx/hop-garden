<?php

namespace App\Tests\Functional\Controller;

use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Plant\Entity\Plant;
use App\Plate\Model\PlatePart;
use App\Reminder\Model\ReminderType;
use App\Tests\Utils\Garden\GardenPathsTrait;
use App\Tests\Utils\Security\LogInTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The plant card, a page of a garden journal, keeps what the tests and the demo video rely on.
 */
final class PlantCardTest extends WebTestCase
{
    use GardenPathsTrait;
    use LogInTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->logIn($this->client);
    }

    public function testTheDraftCardShowsThePlateAndOneCardPerPart(): void
    {
        $plant = PlantFactory::createOne();

        $crawler = $this->client->request('GET', self::pathOf($plant) . '/card');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#plant-card-' . $plant->getId() . '-frame > article.card.crowned.plant-card.is-draft#plant-card-' . $plant->getId());
        self::assertSelectorTextContains('.card-head .kicker', 'Tab. ' . strtoupper(substr($plant->getId()->toRfc4122(), -4)));
        self::assertSelectorTextContains('.card-head h3 a[href="' . self::pathOf($plant) . '"]', 'Bigleaf hydrangea');
        self::assertSelectorTextContains('.card-head .latin', 'Hydrangea macrophylla');
        self::assertSelectorTextSame('.card-head .status-draft', 'Draft');
        self::assertSelectorExists('.plant-card-plate img[src$=".webp"][alt="Hydrangea hortensia"]');
        self::assertSame(
            array_map(static fn (PlatePart $part): string => $part->latinLabel(), PlatePart::cases()),
            $crawler->filter('.plant-card-part h4 em')->each(static fn ($em): string => $em->text()),
        );
        self::assertSelectorExists('.plant-card-hardiness .hardiness-scale[aria-label^="Hardy down to"]');
        self::assertSame(['Hardiness'], $crawler->filter('tr.needs-review th')->each(static fn ($th): string => $th->text()));
        self::assertCount(1, $crawler->filter('.confidence'), 'Only the values to verify say how sure the assistant is.');
        self::assertSelectorTextContains('.card-foot .review-note', 'Review the highlighted values');
        self::assertSelectorTextContains('.card-foot .plate-credit', 'Hydrangea hortensia');
        self::assertSelectorExists('turbo-frame#plant-card-' . $plant->getId() . '-frame:not([target]) .card-foot form[action$="/validate"]:not([data-turbo-frame]) button', 'Validated in its frame.');
    }

    public function testThePageOfTheSpeciesHoldsTheRemindersOfItsParts(): void
    {
        $plant = PlantFactory::new()->validated()->create();

        $crawler = $this->client->request('GET', self::pathOf($plant));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.breadcrumb .page-label', 'Species');
        self::assertSelectorTextContains('.breadcrumb', 'Hydrangea macrophylla');
        self::assertSelectorExists('article.plant-card.is-validated');
        self::assertSelectorNotExists('.confidence');
        foreach (ReminderType::cases() as $type) {
            self::assertCount(1, $crawler->filter('.plant-card-part form[action$="/reminders/' . $type->value . '"] button.toggle[role="switch"]'));
        }
    }

    public function testTheGardenListsItsPlants(): void
    {
        PlantFactory::createOne(['commonName' => 'Panicled hydrangea']);
        $planting = PlantingFactory::new()->of(PlantFactory::new()->validated()->create())->create();

        $this->client->request('GET', self::inGarden('/garden'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.drafts', 'Panicled hydrangea');
        self::assertSelectorTextContains('a.plant-tile[href="' . self::pathOf($planting) . '"]', 'Bigleaf hydrangea');
    }
}
