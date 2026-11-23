<?php

namespace App\Tests\Integration\Twig\Components;

use App\Factory\UserFactory;
use App\Garden\Entity\Garden;
use App\Garden\Form\Model\PlantingFormData;
use App\Garden\Form\PlantingType;
use App\Plant\Form\Model\NewPlantFormData;
use App\Plant\Form\NewPlantType;
use App\Story\DefaultGardenStory;
use App\Tests\Utils\Http\UnavailableDataSources;
use App\Twig\Components\SpeciesPicker;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
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

        self::getContainer()->get('router')->getContext()->setParameter('garden', 'the-garden-of-the-tests');
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
        $picker = $this->inTheGarden(['form' => self::getContainer()->get(FormFactoryInterface::class)->create(NewPlantType::class, $data)->createView(), 'required' => true]);

        $crawler = $picker->render()->crawler();
        self::assertSame('Trefle does not know this name: try its Latin name.', $crawler->filter('.garden-species-candidates .hint')->text());
        self::assertSame('/g/the-garden-of-the-tests/assistant?message=' . rawurlencode('Draft the care sheet of unknown shrub'), $crawler->filter('.garden-species-assistant a')->attr('href'), 'The assistant of the garden, with the name as typed.');

        $crawler = $picker->set('name', 'hortensia')->render()->crawler();
        self::assertSame('Which species is it?', $crawler->filter('.garden-species-label')->text(), 'Not optional.');
        self::assertSame('/g/the-garden-of-the-tests/assistant?message=' . rawurlencode('Draft the care sheet of Hydrangea paniculata'), $picker->set('trefleId', '191052')->render()->crawler()->filter('.garden-species-assistant a')->attr('href'));
    }

    public function testItIsReachedInTheGardenOfThePageByItsMembersOnly(): void
    {
        $picker = $this->inTheGarden(['form' => $this->formView(new PlantingFormData())]);
        self::assertStringStartsWith('/g/the-garden-of-the-tests/_components/SpeciesPicker', $picker->render()->crawler()->filter('[data-controller~="live"]')->attr('data-live-url-value') ?? '');

        $this->expectException(NotFoundHttpException::class);
        $picker->actingAs(UserFactory::new()->approved()->create())->set('name', 'hortensia');
    }

    private function picker(string $name = ''): TestLiveComponent
    {
        $data = new PlantingFormData();
        $data->name = $name;

        return $this->inTheGarden(['form' => $this->formView($data)]);
    }

    /**
     * Its requests go through the firewall and the garden of their URL, /g/{garden}/_components, as from its page: of the
     * garden of the test, by its owner. The URLs of the component come from the router of the test, which the request of
     * a page gives the garden (App\Garden\GardenOfTheUrl): one kernel for all its requests, and the garden given back
     * after each.
     *
     * @param array<string, mixed> $props
     */
    private function inTheGarden(array $props): TestLiveComponent
    {
        $garden = DefaultGardenStory::get('garden');
        self::assertInstanceOf(Garden::class, $garden);
        $context = self::getContainer()->get('router')->getContext();
        $context->setParameter('garden', $garden->getSlug());
        self::getContainer()->get('event_dispatcher')->addListener(KernelEvents::FINISH_REQUEST, static fn () => $context->setParameter('garden', $garden->getSlug()), -1000);
        $client = self::getContainer()->get('test.client');
        $client->disableReboot();

        return $this->createLiveComponent('SpeciesPicker', $props, $client)->actingAs($garden->getOwner());
    }

    private function formView(PlantingFormData $data): FormView
    {
        return self::getContainer()->get(FormFactoryInterface::class)->create(PlantingType::class, $data, ['garden' => DefaultGardenStory::get('garden')])->createView();
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
