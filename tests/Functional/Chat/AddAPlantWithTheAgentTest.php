<?php

namespace App\Tests\Functional\Chat;

use App\Factory\PlantFactory;
use App\Factory\SpaceFactory;
use App\Factory\UserFactory;
use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Garden\Model\Soil;
use App\Garden\PlantingRepository;
use App\Garden\SpaceRepository;
use App\Locale\Model\SupportedLocale;
use App\Plant\Entity\Plant;
use App\Plant\Model\Exposure;
use App\Plant\PlantRepository;
use App\Tests\Utils\Chat\ScriptedGardener;
use App\Tests\Utils\Chat\WorkerTrait;
use App\Tests\Utils\Env\EnvVarsTrait;
use App\Tests\Utils\Garden\GardenPathsTrait;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Je viens de planter un hortensia sur la terrasse", from the chat to the garden, as the browser plays it: the message
 * goes to the worker, the page shows what the agent answered, a card loads in its frame, a tap answers in it, and the
 * card that says it is done sends its message to the agent. ScriptedGardener plays the model.
 */
final class AddAPlantWithTheAgentTest extends WebTestCase
{
    use EnvVarsTrait;
    use GardenPathsTrait;
    use LogInTrait;
    use WorkerTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->logIn($this->client);
    }

    /**
     * A: the species and the space exist, the agent proposes the plant at once.
     */
    #[TestWith(['1'])]
    #[TestWith(['0'])]
    public function testAKnownSpeciesInAKnownSpace(string $demo): void
    {
        $this->setEnvVar('APP_DEMO', $demo);
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->logIn($this->client);
        $hydrangea = PlantFactory::new()->validated()->create();
        $terrace = SpaceFactory::createOne(['name' => 'Terrace', 'exposure' => Exposure::PartShade, 'soil' => Soil::Loamy]);

        $page = $this->say(ScriptedGardener::PLANTED . ' sur la terrasse');

        self::assertCount(0, $page->filter('.chat-choices, .chat-card article.plant-card'), 'No question, no draft.');
        $card = $this->card($page, 'planting');
        self::assertSame('Add to my garden', $card->filter('.kicker')->text());
        self::assertSame('Hortensia', $card->filter('input[name$="[name]"]')->attr('value'));
        self::assertStringContainsString('Hydrangea macrophylla', $card->filter('.proposal-facts')->text());
        self::assertStringContainsString('Terrace', $card->filter('.proposal-facts')->text());
        self::assertSame(0, $this->plantings()->count([]), 'Nothing is written before the tap.');

        $added = $this->tap($card, 'Add to my garden');

        $planting = $this->plantingOf($hydrangea);
        self::assertSame('Hortensia', $planting->getName());
        self::assertSame($terrace->getId()->toRfc4122(), $planting->getSpace()?->getId()->toRfc4122());
        self::assertSame('Added', $added->filter('.status')->text());
        self::assertSame(self::pathOf($planting), $added->filter('a.button-next')->attr('href'));

        $page = $this->say($this->noticeOf($added));
        self::assertSame('I added Hortensia to my garden, in Terrace.', $page->filter('#chat-log .bubble-user')->last()->text());
        self::assertSame(ScriptedGardener::ADDED, $page->filter('#chat-log .bubble-assistant')->last()->text());
        self::assertSame('Added', $this->card($page, 'planting')->filter('.status')->text(), 'Added, after a reload too.');
        self::assertSame('1' === $demo, $page->filter('.tool-call')->count() > 0, 'The MCP lines in demo mode only.');
    }

    /**
     * A, when the user did not say where: the spaces as choices, whatever APP_DEMO.
     */
    #[TestWith(['1'])]
    #[TestWith(['0'])]
    public function testAKnownSpeciesInASpaceToChoose(string $demo): void
    {
        $this->setEnvVar('APP_DEMO', $demo);
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->logIn($this->client);
        $hydrangea = PlantFactory::new()->validated()->create();
        SpaceFactory::createOne(['name' => 'Balcony']);
        $terrace = SpaceFactory::createOne(['name' => 'Terrace', 'exposure' => Exposure::PartShade, 'soil' => Soil::Loamy]);

        $page = $this->say(ScriptedGardener::PLANTED);

        self::assertSame(ScriptedGardener::WHERE_QUESTION, $page->filter('.chat-choices-question')->text());
        $options = $page->filter('.chat-choices button.chat-choice:not([disabled])');
        self::assertSame(['Balcony', 'Terrace', 'Un nouvel espace'], $options->each(static fn (Crawler $option): string => $option->text()));
        self::assertCount(0, $page->filter('.chat-proposal'), 'No card before the answer.');

        $page = $this->say((string) $options->eq(1)->attr('data-chat-text-param'));

        self::assertCount(0, $page->filter('.chat-choices button.chat-choice:not([disabled])'), 'Answered.');
        $this->tap($this->card($page, 'planting'), 'Add to my garden');
        self::assertSame($terrace->getId()->toRfc4122(), $this->plantingOf($hydrangea)->getSpace()?->getId()->toRfc4122());
    }

    /**
     * B: a new space, created from its card, then the plant in it.
     */
    public function testAKnownSpeciesInANewSpace(): void
    {
        $hydrangea = PlantFactory::new()->validated()->create();

        $page = $this->say(ScriptedGardener::PLANTED . ' sur le balcon');

        self::assertSame(ScriptedGardener::SPACE_READY, $page->filter('#chat-log .bubble-assistant')->last()->text());
        $card = $this->card($page, 'space');
        self::assertSame('New space', $card->filter('.kicker')->text());
        self::assertSame('Balcon', $card->filter('input[name$="[name]"]')->attr('value'), 'Its name prefilled.');
        self::assertSame([], $this->spaces()->findAll(), 'Nothing is written before the tap.');

        $created = $this->tap($card, 'Create the space', ['exposure' => 'sun', 'soil' => 'loamy']);

        $balcony = $this->spaces()->findOneByName($this->gardenOfTheTest(), 'Balcon') ?? throw new \LogicException('The space is not created.');
        self::assertSame(Exposure::Sun, $balcony->getExposure());
        self::assertSame(Soil::Loamy, $balcony->getSoil());
        self::assertSame('Created', $created->filter('.status')->text());

        $page = $this->say($this->noticeOf($created));
        self::assertSame('I created the space Balcon.', $page->filter('#chat-log .bubble-user')->last()->text());
        $this->tap($this->card($page, 'planting'), 'Add to my garden');

        self::assertSame($balcony->getId()->toRfc4122(), $this->plantingOf($hydrangea)->getSpace()?->getId()->toRfc4122());
        self::assertSame('Created', $this->card($this->client->request('GET', self::inGarden('/assistant')), 'space')->filter('.status')->text(), 'Created, after a reload too.');
    }

    /**
     * C: a new species, chosen among the candidates of Trefle, its draft validated on its card, then the plant.
     */
    public function testANewSpecies(): void
    {
        SpaceFactory::createOne(['name' => 'Terrace', 'exposure' => Exposure::PartShade, 'soil' => Soil::Loamy]);

        $page = $this->say(ScriptedGardener::PLANTED . ' sur la terrasse');

        self::assertSame(ScriptedGardener::SPECIES_QUESTION, $page->filter('.chat-choices-question')->text());
        $options = $page->filter('.chat-choices button.chat-choice:not([disabled])');
        self::assertSame(['Bigleaf hydrangea (Hydrangea macrophylla)', 'Panicled hydrangea (Hydrangea paniculata)', 'Smooth hydrangea (Hydrangea arborescens)'], $options->each(static fn (Crawler $option): string => $option->text()));
        self::assertSame([ScriptedGardener::CHOICES_HINT], $page->filter('#chat-log .bubble-assistant')->each(static fn (Crawler $bubble): string => $bubble->text()));

        // A tap on a choice sends its text, as if typed
        $page = $this->say((string) $options->first()->attr('data-chat-text-param'));

        self::assertCount(1, $page->filter('.chat-choices.is-answered button[disabled]')->first());
        $draft = $page->filter('.chat-card article.plant-card.is-draft');
        self::assertCount(1, $draft);
        $hydrangea = $this->plants()->findOneBy(['scientificName' => 'Hydrangea macrophylla']) ?? throw new \LogicException('No draft.');

        $this->client->submit($draft->selectButton('Validate')->form(), [], ['HTTP_TURBO_FRAME' => 'plant-card-' . $hydrangea->getId() . '-frame']);
        $validated = $this->client->followRedirect();
        self::assertSame(0, $this->plantings()->count([]), 'Validating the care sheet adds no plant.');

        $page = $this->say($this->noticeOf($validated));
        self::assertSame('I validated the care sheet of Bigleaf hydrangea.', $page->filter('#chat-log .bubble-user')->last()->text());
        $this->tap($this->card($page, 'planting'), 'Add to my garden');

        self::assertSame('Terrace', $this->plantingOf($hydrangea)->getSpace()?->getName());
    }

    /**
     * B and C in French: each card tells the agent in the language of the interface, as the bubble of the user shows it.
     */
    public function testTheCardsTellTheAgentInTheLanguageOfTheInterface(): void
    {
        $this->logIn($this->client, UserFactory::new()->approved()->create(['preferredLocale' => SupportedLocale::French]));

        $page = $this->say(ScriptedGardener::PLANTED . ' sur le balcon');
        $page = $this->say((string) $page->filter('.chat-choices button.chat-choice:not([disabled])')->first()->attr('data-chat-text-param'));
        $hydrangea = $this->plants()->findOneBy(['scientificName' => 'Hydrangea macrophylla']) ?? throw new \LogicException('No draft.');
        $draft = $page->filter('.chat-card article.plant-card.is-draft');
        $this->client->submit($draft->selectButton('Valider')->form(), [], ['HTTP_TURBO_FRAME' => 'plant-card-' . $hydrangea->getId() . '-frame']);

        $page = $this->say($this->noticeOf($this->client->followRedirect()));
        self::assertSame('J’ai validé la fiche de culture de l’espèce Bigleaf hydrangea.', $page->filter('#chat-log .bubble-user')->last()->text());
        $created = $this->tap($this->card($page, 'space'), 'Créer l’espace', ['exposure' => 'sun', 'soil' => 'loamy']);
        self::assertSame('Créé', $created->filter('.status')->text());

        $page = $this->say($this->noticeOf($created));
        self::assertSame('J’ai créé l’espace Balcon.', $page->filter('#chat-log .bubble-user')->last()->text());
        $added = $this->tap($this->card($page, 'planting'), 'Ajouter à mon jardin');
        self::assertSame('Ajoutée', $added->filter('.status')->text());

        $page = $this->say($this->noticeOf($added));
        self::assertSame('J’ai ajouté Hortensia à mon jardin, dans l’espace Balcon.', $page->filter('#chat-log .bubble-user')->last()->text());
        self::assertSame(ScriptedGardener::ADDED, $page->filter('#chat-log .bubble-assistant')->last()->text(), 'The agent understood.');
        self::assertSame('Balcon', $this->plantingOf($hydrangea)->getSpace()?->getName());
    }

    /**
     * Sends a message as the chat form does, runs the worker, and reloads the page.
     */
    private function say(string $text): Crawler
    {
        $crawler = $this->client->request('GET', self::inGarden('/assistant'));
        $this->client->submit($crawler->filter('form.chat-form')->form(), ['text' => $text]);
        $this->runTheWorker();

        return $this->client->request('GET', self::inGarden('/assistant'));
    }

    /**
     * The last card of this kind in the chat, as its frame loads it.
     */
    private function card(Crawler $page, string $kind): Crawler
    {
        $frame = $page->filter(\sprintf('.chat-proposal turbo-frame[id^="%s-proposal-"]', $kind))->last();
        self::assertCount(1, $frame, \sprintf('No %s card.', $kind));

        $card = $this->client->request('GET', (string) $frame->attr('src'), server: ['HTTP_TURBO_FRAME' => (string) $frame->attr('id')]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $card->filter(\sprintf('turbo-frame#%s', $frame->attr('id'))), 'The card answers in its frame.');

        return $card;
    }

    /**
     * @param array<string, string> $fields by the name of the field in its form
     */
    private function tap(Crawler $card, string $button, array $fields = []): Crawler
    {
        $form = $card->selectButton($button)->form();
        $values = [];
        foreach ($fields as $field => $value) {
            $values[\sprintf('%s[%s]', $form->getName(), $field)] = $value;
        }

        $this->client->submit($form, $values, ['HTTP_TURBO_FRAME' => (string) $card->filter('turbo-frame')->attr('id')]);
        self::assertResponseStatusCodeSame(303);

        return $this->client->followRedirect();
    }

    private function noticeOf(Crawler $card): string
    {
        return (string) $card->filter('[data-chat-notice]')->attr('data-chat-notice');
    }

    private function plantingOf(Plant $plant): Planting
    {
        $plantings = $this->plantings()->findBy(['plant' => $plant->getId()]);
        self::assertCount(1, $plantings);
        self::assertNotNull($plantings[0]->getProposalId());

        return $plantings[0];
    }

    private function plantings(): PlantingRepository
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(PlantingRepository::class);
    }

    private function spaces(): SpaceRepository
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(SpaceRepository::class);
    }

    private function plants(): PlantRepository
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(PlantRepository::class);
    }
}
