<?php

namespace App\Tests\Integration\Twig\Components;

use App\Factory\CareSheetFactory;
use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Plant\Entity\CareSheet;
use App\Plant\Entity\Plant;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\Source;
use App\Reminder\Model\ReminderType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class PlantCardTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    /**
     * The card signs its forms with the session, as in the request of a page.
     */
    protected function setUp(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);
    }

    public function testADraftIsValidatedInItsFrame(): void
    {
        $plant = PlantFactory::createOne();

        $crawler = $this->card($plant);

        $frameId = 'plant-card-' . $plant->getId() . '-frame';
        self::assertNull($crawler->filter('turbo-frame#' . $frameId)->attr('target'), 'Its forms answer in the frame.');
        self::assertSame('_top', $crawler->filter('.card-head h3 a')->attr('data-turbo-frame'), 'The page of the species leaves the frame.');
        self::assertCount(1, $crawler->filter('article#plant-card-' . $plant->getId() . '.plant-card.is-draft'));
        self::assertSame('Draft', $crawler->filter('.card-head .status.status-draft')->text());
        self::assertNull($crawler->filter('form[action$="/validate"]')->attr('data-turbo-frame'));
        self::assertNotNull($crawler->filter('form[action$="/validate"]')->attr('data-chat-follow-up'), 'In the chat, its outcome tells the agent.');
        self::assertNull($crawler->filter('article.plant-card')->attr('data-chat-notice'), 'Nothing to tell yet.');
        self::assertSame('Validate', $crawler->filter('form[action$="/validate"] button[type="submit"].button')->text());
        self::assertSame('Review the highlighted values: the assistant is not sure of them.', $crawler->filter('.review-note')->text());
        self::assertSame('Validate the card to set reminders.', $crawler->filter('.actions .hint')->text());
        self::assertCount(0, $crawler->filter('.plate-reminder'), 'No reminder for a draft.');
    }

    public function testADraftCreatedByHandAsksToFillItIn(): void
    {
        $crawler = $this->card(PlantFactory::createOne(['careSheet' => CareSheet::blank([new Source(Source::TREFLE)])]));

        self::assertSame('Fill in the highlighted values, then validate. A value left as is stays highlighted, even when it is right.', $crawler->filter('.review-note')->text(), 'The assistant has nothing to do with it.');
        self::assertCount(\count(CareSheetField::cases()), $crawler->filter('tr.needs-review'), 'Every value to fill in.');
    }

    public function testTheValuesToVerifySayHowSureTheAssistantIs(): void
    {
        $crawler = $this->card(PlantFactory::createOne());

        self::assertSame(['Hardiness'], $crawler->filter('tr.needs-review th')->each(static fn (Crawler $th): string => $th->text()), 'The only value of low confidence.');
        self::assertSame('low', $crawler->filter('tr.row-min_temperature_c .confidence.confidence-low')->text());
        self::assertSame('Confidence of the assistant: low', $crawler->filter('tr.row-min_temperature_c .confidence')->attr('title'));
        self::assertCount(1, $crawler->filter('.confidence'), 'The values of high confidence say nothing.');
    }

    public function testEachPartOfThePlantShowsTheCareItGoverns(): void
    {
        $crawler = $this->card(PlantFactory::new()->validated()->create(['careSheet' => CareSheetFactory::new(['pruningMonths' => [2, 3]])]));

        self::assertSame(['Gemma', 'Folium', 'Caulis', 'Flos', 'Surculus'], $crawler->filter('.plant-card-part h4 em')->each(static fn (Crawler $em): string => $em->text()));
        self::assertSame(['icon-snowflake', 'icon-sun', 'icon-secateurs', 'icon-flower', 'icon-sprout'], $crawler->filter('.plant-card-part h4 .pict svg')->each(static fn (Crawler $svg): string => explode(' ', (string) $svg->attr('class'))[1]));
        self::assertSame(['Hardiness', 'Frost sensitive'], $crawler->filter('.plant-card-part')->first()->filter('th')->each(static fn (Crawler $th): string => $th->text()));
        self::assertSame(['F', 'M'], $crawler->filter('tr.row-pruning_months .month-ruler .is-on')->each(static fn (Crawler $month): string => $month->text()));
        self::assertCount(0, $crawler->filter('tr.needs-review, .confidence'), 'A validated plant has nothing left to verify.');
    }

    public function testTheHardinessScaleRunsFromTheColdestToTheFrostAlert(): void
    {
        $crawler = $this->card(PlantFactory::new()->validated()->create(['careSheet' => CareSheetFactory::new(['minTemperatureC' => -15])]));

        $scale = $crawler->filter('.plant-card-hardiness .hardiness-scale');
        self::assertSame('Hardy down to -15 °C, frost alert below -13 °C', $scale->attr('aria-label'));
        self::assertSame('left: 37.5%', $scale->filter('.hardiness-coldest')->attr('style'), 'From −30 °C to 10 °C.');
        self::assertSame('−15 °C', $scale->filter('.hardiness-coldest')->text());
        self::assertSame('frost alert −13 °C', $scale->filter('.hardiness-threshold')->text());
        self::assertSame(['−30', '−20', '0', '10'], $scale->filter('.hardiness-tick > span')->each(static fn (Crawler $label): string => $label->text()), 'No label under the one of the threshold.');
    }

    public function testAValidatedCardSetsItsRemindersInTheirFrames(): void
    {
        $plant = PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning)->create(['careSheet' => CareSheetFactory::new(['pruningMonths' => [3], 'deadheadingMonths' => [], 'cuttingMonths' => [6]])]);

        $crawler = $this->card($plant);

        self::assertCount(1, $crawler->filter('article.plant-card.is-validated'));
        self::assertSame('Validated', trim($crawler->filter('.card-head .status.status-validated')->text()), 'No plant of the garden follows it yet.');
        self::assertSame('I validated the care sheet of Bigleaf hydrangea.', $crawler->filter('article.plant-card')->attr('data-chat-notice'), 'In the chat, it tells the agent.');
        self::assertCount(1, $crawler->filter('.card-head .status svg.icon-check'));
        self::assertSame('/plants/' . $plant->getId() . '/edit', $crawler->filter('a.button.button-secondary')->attr('href'));
        self::assertNull($crawler->filter('a.button.button-secondary')->attr('data-turbo-frame'), 'Edited in its frame.');
        self::assertCount(0, $crawler->filter('form[action$="/validate"], a:contains("Set reminders")'));
        self::assertSame(['frost', 'pruning', 'deadheading', 'cutting'], $crawler->filter('turbo-frame[id^="plant-' . $plant->getId() . '-reminder-"]')->each(static fn (Crawler $frame): string => substr((string) $frame->attr('id'), \strlen('plant-' . $plant->getId() . '-reminder-'))));
        self::assertSame('true', $crawler->filter('#plant-' . $plant->getId() . '-reminder-pruning .toggle')->attr('aria-checked'));
        self::assertSame('Reminder on the 1st of Mar', $crawler->filter('#plant-' . $plant->getId() . '-reminder-pruning .plate-reminder-text')->text());
        self::assertNotNull($crawler->filter('#plant-' . $plant->getId() . '-reminder-deadheading .toggle')->attr('disabled'), 'No month to remind.');
        self::assertSame('Alert below -13 °C in the forecast', $crawler->filter('#plant-' . $plant->getId() . '-reminder-frost .plate-reminder-text')->text());
    }

    public function testAValidatedCardLeadsToMyPlant(): void
    {
        $plant = PlantFactory::new()->validated()->create();
        self::assertCount(0, $this->card($plant)->filter('a:contains("See my plant")'), 'No plant of the garden follows it yet.');

        $planting = PlantingFactory::new()->of($plant)->create();

        $crawler = $this->card($plant);
        self::assertSame('In your garden', trim($crawler->filter('.card-head .status.status-validated')->text()));
        $link = $crawler->filter('a.button-next');
        self::assertSame('See my plant', trim($link->text()));
        self::assertSame('/garden/' . $planting->getId(), $link->attr('href'));
        self::assertSame('_top', $link->attr('data-turbo-frame'));
    }

    private function card(Plant $plant): Crawler
    {
        return $this->renderTwigComponent('PlantCard', ['plant' => $plant])->crawler();
    }
}
