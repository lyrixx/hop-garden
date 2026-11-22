<?php

namespace App\Tests\Functional\Controller;

use App\Plant\Entity\Plant;
use App\Plate\Model\PlatePart;
use App\Reminder\Model\ReminderType;
use App\Tests\Utils\Factory\PlantFactory;
use App\Tests\Utils\Factory\PlantingFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The plant card, a page of a garden journal, keeps what the tests and the demo video rely on.
 */
final class PlantCardTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testTheDraftCardShowsThePlateAndOneCardPerPart(): void
    {
        $plant = $this->save(PlantFactory::draft());

        $crawler = $this->client->request('GET', '/plants/' . $plant->getId() . '/card');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#plant-card-' . $plant->getId() . '-frame > article.card.crowned.plant-card.is-draft#plant-card-' . $plant->getId());
        self::assertSelectorTextContains('.card-head .kicker', 'Tab. ' . $plant->getId());
        self::assertSelectorTextContains('.card-head h3 a[href="/plants/' . $plant->getId() . '"]', 'Bigleaf hydrangea');
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
        $plant = $this->save(PlantFactory::validated());

        $crawler = $this->client->request('GET', '/plants/' . $plant->getId());

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
        $this->save(PlantFactory::draft('Panicled hydrangea'));
        $planting = PlantingFactory::of($this->save(PlantFactory::validated()));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($planting);
        $entityManager->flush();

        $this->client->request('GET', '/garden');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.drafts', 'Panicled hydrangea');
        self::assertSelectorTextContains('a.plant-tile[href="/garden/' . $planting->getId() . '"]', 'Bigleaf hydrangea');
    }

    private function save(Plant $plant): Plant
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($plant);
        $entityManager->flush();

        return $plant;
    }
}
