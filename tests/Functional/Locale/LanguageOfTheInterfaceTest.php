<?php

namespace App\Tests\Functional\Locale;

use App\Factory\CareSheetFactory;
use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Factory\SpaceFactory;
use App\Garden\Model\Soil;
use App\Locale\Model\SupportedLocale;
use App\Locale\PreferredLocale;
use App\Plant\Model\Exposure;
use App\Reminder\Model\ReminderType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The language is a setting of the application, never of the URL: until one is chosen, the interface stays in English
 * and offers the one of the browser.
 */
final class LanguageOfTheInterfaceTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testABrowserInFrenchIsOfferedFrenchInFrench(): void
    {
        $crawler = $this->client->request('GET', '/garden', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9,en;q=0.8']);

        self::assertResponseIsSuccessful();
        $banner = $crawler->filter('.locale-banner');
        self::assertSame('fr', $banner->attr('lang'));
        self::assertSame('Hop Garden existe en français', $banner->filter('p')->text());
        self::assertSame(
            [['Passer en français', 'fr'], ['Rester en anglais', 'en']],
            $banner->filter('button')->each(static fn (Crawler $button): array => [$button->text(), $button->attr('value')]),
        );
        self::assertSame('/garden', $banner->filter('input[name="returnTo"]')->attr('value'));
        self::assertSame('en', $crawler->filter('html')->attr('lang'), 'Nothing changes without a tap.');
        self::assertSelectorTextContains('.nav', 'My garden');
        self::assertFalse(self::getContainer()->get(PreferredLocale::class)->isChosen());
    }

    #[TestWith(['en-GB,en;q=0.9'])]
    #[TestWith(['de-DE,de;q=0.9'])]
    #[TestWith([''])]
    public function testNoBannerForAnotherBrowser(string $acceptLanguage): void
    {
        $this->client->request('GET', '/garden', server: '' !== $acceptLanguage ? ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage] : []);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.locale-banner');
    }

    public function testSwitchingToFrench(): void
    {
        $crawler = $this->client->request('GET', '/plants', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR']);

        $this->client->submit($crawler->selectButton('Passer en français')->form());

        self::assertResponseRedirects('/plants', 303, 'The same page, in French.');
        $crawler = $this->client->followRedirect();
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextSame('.nav a[href="/garden"]', 'Mon jardin');
        self::assertSelectorTextSame('h1', 'Espèces');
        self::assertSelectorNotExists('.locale-banner', 'Chosen: it does not come back.');
        self::assertSame(SupportedLocale::French, self::getContainer()->get(PreferredLocale::class)->get());
    }

    public function testStayingInEnglishIsAChoiceToo(): void
    {
        $crawler = $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR']);

        $this->client->submit($crawler->selectButton('Rester en anglais')->form());

        $crawler = $this->client->followRedirect();
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertSelectorNotExists('.locale-banner');
        self::assertTrue(self::getContainer()->get(PreferredLocale::class)->isChosen());
    }

    public function testTheFooterSwitchesTheLanguage(): void
    {
        $crawler = $this->client->request('GET', '/spaces');
        self::assertSame(
            [['English', 'en', 'true'], ['Français', 'fr', 'false']],
            $crawler->filter('.locale-switch button')->each(static fn (Crawler $button): array => [$button->text(), $button->attr('lang'), $button->attr('aria-pressed')]),
        );

        $this->client->submit($crawler->filter('.locale-switch')->selectButton('Français')->form());
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextSame('h1', 'Vos espaces');
        self::assertSame('true', $crawler->filter('.locale-switch button[lang="fr"]')->attr('aria-pressed'));

        $this->client->submit($crawler->filter('.locale-switch')->selectButton('English')->form());
        $this->client->followRedirect();

        self::assertSelectorTextSame('h1', 'Your spaces', 'And back.');
    }

    public function testAForgedTokenChangesNothing(): void
    {
        $this->client->request('POST', '/locale', ['_token' => 'forged', 'locale' => 'fr', 'returnTo' => '/']);

        self::assertResponseStatusCodeSame(401);
        self::assertFalse(self::getContainer()->get(PreferredLocale::class)->isChosen());
    }

    #[TestWith(['de', '/'])]
    #[TestWith(['fr', '//evil.example/'])]
    #[TestWith(['fr', '/\evil.example/'])]
    #[TestWith(['fr', 'https://evil.example/'])]
    public function testOnlyOurLanguagesAndOurPages(string $locale, string $returnTo): void
    {
        $token = $this->client->request('GET', '/')->filter('.locale-switch input[name="_token"]')->attr('value');

        $this->client->request('POST', '/locale', ['_token' => $token, 'locale' => $locale, 'returnTo' => $returnTo]);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse(self::getContainer()->get(PreferredLocale::class)->isChosen());
    }

    public function testThePagesInFrench(): void
    {
        self::mockTime('2026-11-22 09:30');
        $rose = PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning)->create(['commonName' => 'Rosier grimpant', 'careSheet' => CareSheetFactory::new(['minTemperatureC' => -15, 'pruningMonths' => [2, 3], 'exposure' => Exposure::Sun])]);
        $draft = PlantFactory::createOne(['commonName' => 'Hortensia']);
        $space = SpaceFactory::createOne(['name' => 'Terrasse', 'exposure' => Exposure::PartShade, 'soil' => Soil::Loamy]);
        $planting = PlantingFactory::new()->of($rose)->inSpace($space)->create();
        self::getContainer()->get(PreferredLocale::class)->set(SupportedLocale::French);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $pages = [
            '/' => 'Votre assistant de jardinage',
            '/garden' => 'Mon jardin',
            '/garden/new' => 'Ajouter une plante à votre jardin',
            '/garden/' . $planting->getId() => 'Recommandations',
            '/garden/' . $planting->getId() . '/edit' => 'Modifier les infos de jardin de ' . $planting->getName(),
            '/plants' => 'Espèces',
            '/plants/new' => 'Nouvelle espèce',
            '/plants/' . $rose->getId() => 'Dans votre jardin',
            '/plants/' . $draft->getId() . '/edit' => 'Relire le brouillon de Hydrangea macrophylla',
            '/spaces' => 'Vos espaces',
            '/spaces/new' => 'Ajouter un espace',
            '/spaces/' . $space->getId() => 'Ce qui y pousse',
            '/notifications' => 'Prévisions de gel',
        ];
        foreach ($pages as $url => $text) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertStringContainsString($text, $crawler->filter('main')->text(), $url);
            self::assertSame('fr', $crawler->filter('html')->attr('lang'), $url);
        }

        $crawler = $this->client->request('GET', '/garden/' . $planting->getId());
        self::assertSame(
            [['Exposition', 'Conseil'], ['Arrosage', 'Conseil'], ['Taille', 'Conseil'], ['Gel', 'Tout va bien']],
            $crawler->filter('.recommendation-heading')->each(static fn (Crawler $heading): array => [$heading->filter('strong')->text(), $heading->filter('.tag')->text()]),
            'The enums too.',
        );
        self::assertStringContainsString('Elle préfère le plein soleil et se trouve ici à la mi-ombre', $crawler->filter('.recommendations')->text(), 'Its articles and prepositions.');
        self::assertStringContainsString('Taillez-la en février et mars.', $crawler->filter('.recommendations')->text(), 'The months with Intl.');
        self::assertSelectorTextContains('.planting-place', 'Mi-ombre', 'The exposure of its space.');

        $crawler = $this->client->request('GET', '/plants/' . $rose->getId());
        self::assertSame('Rusticité', $crawler->filter('.plant-card .row-min_temperature_c th')->text());
        self::assertSame("-15\u{a0}°C", $crawler->filter('.plant-card .row-min_temperature_c td')->text());
        self::assertSame('Rosier grimpant', $crawler->filter('.plant-card h3')->text(), 'What the user or the agent wrote stays as is.');

        $crawler = $this->client->request('GET', '/notifications');
        self::assertSame('dimanche 22 novembre', $crawler->filter('.forecast-date')->first()->text(), 'Dates with Intl, in the timezone of the garden.');
        self::assertSame("-3,2\u{a0}°C", $crawler->filter('.forecast-min')->first()->text());
    }

    public function testAFrenchNameFindsItsSpeciesThroughItsLatinName(): void
    {
        self::getContainer()->get(PreferredLocale::class)->set(SupportedLocale::French);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $crawler = $this->client->request('GET', '/plants/new');

        // Trefle does not know "rosier": the recorded answer of the LLM gives Rosa, which it knows
        $this->client->submit($crawler->selectButton('Créer le brouillon')->form(), ['new_plant[name]' => 'rosier', 'new_plant[trefleId]' => '263311']);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Le brouillon de Dog rose est prêt');
        self::assertStringContainsString('Rosa canina', $crawler->filter('h1')->text());
    }

    public function testTheValidationMessagesInFrench(): void
    {
        self::getContainer()->get(PreferredLocale::class)->set(SupportedLocale::French);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $crawler = $this->client->request('GET', '/spaces/new');

        $this->client->submit($crawler->selectButton('Ajouter l’espace')->form(), ['space[name]' => '  ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Cette valeur ne doit pas être vide.', 'The messages of Symfony.');
        self::assertSelectorTextContains('form label[for="space_name"]', 'Nom');

        $crawler = $this->client->request('GET', '/plants/new');
        $this->client->submit($crawler->selectButton('Créer le brouillon')->form()->disableValidation(), ['new_plant[name]' => 'hortensia']);

        self::assertSelectorTextContains('form', 'Choisissez son espèce parmi celles que Trefle trouve.', 'And ours.');
    }
}
