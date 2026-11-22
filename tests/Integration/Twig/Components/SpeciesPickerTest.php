<?php

namespace App\Tests\Integration\Twig\Components;

use App\Factory\UserFactory;
use App\Garden\Form\Model\PlantingFormData;
use App\Garden\Form\PlantingType;
use App\Plant\Form\Model\NewPlantFormData;
use App\Plant\Form\NewPlantType;
use App\Tests\Utils\Http\UnavailableDataSources;
use App\Twig\Components\SpeciesPicker;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class SpeciesPickerTest extends KernelTestCase
{
    use InteractsWithLiveComponents;
    use InteractsWithTwigComponents;

    public function testTheTypedNameAsksTrefle(): void
    {
        $crawler = $this->picker()->set('name', 'hortensia')->render()->crawler();

        self::assertSame('Which species is it? (optional)', $crawler->filter('.garden-species-label')->text());
        self::assertContains('Hydrangea paniculata · Panicled hydrangea', self::candidates($crawler));
        self::assertSame('191052', $crawler->filter('.garden-species-candidates button:contains("Hydrangea paniculata")')->attr('data-value'));
        self::assertNotNull($crawler->filter('.garden-species-chosen')->attr('hidden'), 'No species picked yet.');
    }

    #[TestWith(['h'])]
    #[TestWith(['  '])]
    #[TestWith([''])]
    public function testTheSearchWaitsForTwoCharacters(string $name): void
    {
        $crawler = $this->picker()->set('name', $name)->render()->crawler();

        self::assertSame('', $crawler->filter('.garden-species-candidates')->html(), 'Empty, so that the CSS hides it.');
    }

    public function testANameTrefleDoesNotKnowIsKept(): void
    {
        $crawler = $this->picker()->set('name', 'unknown shrub')->render()->crawler();

        self::assertSame('Trefle does not know this name: it is kept as is.', $crawler->filter('.garden-species-candidates .hint')->text());
        self::assertSame([], self::candidates($crawler));
    }

    public function testTheNameIsKeptWhenTrefleDoesNotAnswer(): void
    {
        UnavailableDataSources::trefle(self::getContainer());

        $crawler = $this->picker('hortensia')->render()->crawler();

        self::assertSame('Trefle does not answer: your name is kept as is.', $crawler->filter('.garden-species-candidates .hint')->text());
    }

    public function testPickingACandidateTakesItsScientificNameFromTrefle(): void
    {
        $picker = $this->picker('hortensia')->set('trefleId', '191052');

        $component = self::component($picker);
        self::assertSame(191052, $component->trefleId);
        self::assertSame('Hydrangea paniculata', $component->scientificName);
        $crawler = $picker->render()->crawler();
        self::assertNull($crawler->filter('.garden-species-chosen')->attr('hidden'));
        self::assertSame('Hydrangea paniculata', $crawler->filter('.garden-species-chosen em')->text());
        self::assertSame('', $crawler->filter('.garden-species-candidates')->html(), 'No other species to pick once one is.');
    }

    public function testAnIdThatIsNotACandidateOfTheNamePicksNothing(): void
    {
        $component = self::component($this->picker('hortensia')->set('trefleId', '999999'));

        self::assertNull($component->trefleId);
        self::assertNull($component->scientificName);
    }

    public function testChangeClearsThePickedSpecies(): void
    {
        $picker = $this->picker('hortensia')->set('trefleId', '191052')->set('trefleId', '');

        $component = self::component($picker);
        self::assertNull($component->trefleId);
        self::assertNull($component->scientificName);
        self::assertContains('Hydrangea paniculata · Panicled hydrangea', self::candidates($picker->render()->crawler()), 'The candidates of the name again.');
    }

    public function testTheFieldsOfTheFormAreRenderedOnceAndBoundToTheProps(): void
    {
        $data = new PlantingFormData();
        $data->name = 'Hortensia du portail';
        $data->trefleId = 191052;
        $data->scientificName = 'Hydrangea paniculata';

        $crawler = $this->renderTwigComponent('SpeciesPicker', ['form' => $this->formView($data)])->crawler();

        self::assertSame('Hortensia du portail', $crawler->filter('[data-live-ignore] input[name="planting[name]"][data-model="debounce(300)|name"]')->attr('value'));
        self::assertSame('191052', $crawler->filter('[data-live-ignore] input[type="hidden"][name="planting[trefleId]"][data-model="trefleId"]')->attr('value'));
        self::assertSame('Hydrangea paniculata', $crawler->filter('[data-live-ignore] input[type="hidden"][name="planting[scientificName]"][data-model="scientificName"]')->attr('value'));
        self::assertSame('Hydrangea paniculata', $crawler->filter('.garden-species-chosen:not([hidden]) em')->text(), 'The species picked before the form came back.');

        $rerendered = $this->picker()->set('name', 'hortensia')->render()->crawler();
        self::assertSame('', $rerendered->filter('[data-live-ignore]')->html(), 'Left to the browser on re-render.');
    }

    public function testANewSpeciesNeedsOneFromTrefle(): void
    {
        $data = new NewPlantFormData();
        $data->name = 'unknown shrub';
        $picker = $this->createLiveComponent('SpeciesPicker', ['form' => self::getContainer()->get(FormFactoryInterface::class)->create(NewPlantType::class, $data)->createView(), 'required' => true])->actingAs(UserFactory::new()->approved()->create());

        $crawler = $picker->render()->crawler();
        self::assertSame('Trefle does not know this name: try its Latin name.', $crawler->filter('.garden-species-candidates .hint')->text());
        self::assertSame('/?message=' . rawurlencode('Draft the care sheet of unknown shrub'), $crawler->filter('.garden-species-assistant a')->attr('href'), 'The assistant, with the name as typed.');

        $crawler = $picker->set('name', 'hortensia')->render()->crawler();
        self::assertSame('Which species is it?', $crawler->filter('.garden-species-label')->text(), 'Not optional.');
        self::assertSame('/?message=' . rawurlencode('Draft the care sheet of Hydrangea paniculata'), $picker->set('trefleId', '191052')->render()->crawler()->filter('.garden-species-assistant a')->attr('href'));
    }

    private function picker(string $name = ''): TestLiveComponent
    {
        $data = new PlantingFormData();
        $data->name = $name;

        // Its requests go through the firewall: /_components needs a member
        return $this->createLiveComponent('SpeciesPicker', ['form' => $this->formView($data)])->actingAs(UserFactory::new()->approved()->create());
    }

    private function formView(PlantingFormData $data): FormView
    {
        return self::getContainer()->get(FormFactoryInterface::class)->create(PlantingType::class, $data)->createView();
    }

    private static function component(TestLiveComponent $picker): SpeciesPicker
    {
        $component = $picker->component();
        self::assertInstanceOf(SpeciesPicker::class, $component);

        return $component;
    }

    /**
     * @return list<string>
     */
    private static function candidates(Crawler $crawler): array
    {
        return $crawler->filter('.garden-species-candidates button')->each(static fn (Crawler $button): string => $button->text());
    }
}
