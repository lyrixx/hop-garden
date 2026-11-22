<?php

namespace App\Tests\Functional\Controller;

use App\Locale\UserPreferredLocale;
use App\Tests\Utils\Security\LogInTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The public page, on /: what Hop Garden takes care of, with its own top bar.
 */
final class HomeControllerTest extends WebTestCase
{
    use LogInTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testThePublicPageInEnglish(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextSame('h1', 'Know when to prune. Get warned before the frost.');
        self::assertSame(
            [
                'You forget when to prune.',
                'A late frost kills a plant.',
                'Care sheets found online contradict each other.',
                'You no longer know what you planted, or where.',
                'Keeping a garden notebook takes too long.',
                'Your AI assistant does not know your garden.',
            ],
            $crawler->filter('.problem h3')->each(static fn (Crawler $heading): string => $heading->text()),
        );
        self::assertSelectorTextContains('.home-soon .tag', 'Soon', 'Shared gardens are not there yet.');
        self::assertSelectorTextContains('.home-conversation .bubble-user', 'I just planted a hydrangea on the terrace');
        self::assertStringContainsString('/images/home/care-sheet.en-', (string) $crawler->filter('.home-figure img')->first()->attr('src'), 'The capture in English.');
    }

    public function testThePublicPageInFrench(): void
    {
        $this->client->getCookieJar()->set(new Cookie(UserPreferredLocale::COOKIE, 'fr'));

        $crawler = $this->client->request('GET', '/');

        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextSame('h1', 'Savoir quand tailler. Être prévenu avant le gel.');
        self::assertSelectorTextSame('#what-it-solves h2', 'Ce que Hop Garden règle');
        self::assertSelectorTextSame('.home-hero .button', 'Rejoindre la liste d’attente');
        self::assertSelectorTextSame('.public-nav-link', 'Se connecter');
        self::assertStringContainsString('/images/home/reminders.fr-', (string) $crawler->filter('.home-figure img')->last()->attr('src'), 'The capture in French.');
    }

    public function testItsOwnTopBar(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSame('/', $crawler->filter('.topbar .brand')->attr('href'));
        self::assertSelectorTextSame('.public-nav-link', 'Log in');
        self::assertSelectorTextSame('.public-nav .button', 'Join the waiting list');
        self::assertSame(['EN', 'FR'], $crawler->filter('.public-nav .locale-switch button')->each(static fn (Crawler $button): string => $button->text()));
        self::assertSelectorNotExists('.nav', 'Not the navigation of the application.');
        self::assertSelectorNotExists('turbo-mercure-stream-source', 'No reminder toast on a public page.');
    }

    public function testTheTopBarSwitchesTheLanguage(): void
    {
        $crawler = $this->client->request('GET', '/');

        $this->client->submit($crawler->filter('.public-nav .locale-switch')->selectButton('FR')->form());

        self::assertResponseRedirects('/', 303);
        $this->client->followRedirect();
        self::assertSelectorTextSame('h1', 'Savoir quand tailler. Être prévenu avant le gel.');
    }

    public function testABrowserInFrenchIsOfferedFrench(): void
    {
        $crawler = $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9']);

        self::assertSelectorTextSame('h1', 'Know when to prune. Get warned before the frost.', 'Nothing changes without a tap.');
        $this->client->submit($crawler->selectButton('Passer en français')->form());

        self::assertResponseRedirects('/', 303);
        $this->client->followRedirect();
        self::assertSelectorTextSame('h1', 'Savoir quand tailler. Être prévenu avant le gel.');
        self::assertSelectorNotExists('.locale-banner');
    }

    public function testALinkOfTheChatOnTheRootOpensTheAssistant(): void
    {
        $this->logIn($this->client);
        $this->client->request('GET', '/?message=' . rawurlencode('Draft the care sheet of a new species'));

        self::assertResponseRedirects('/assistant?message=' . rawurlencode('Draft the care sheet of a new species'), 301);
        $crawler = $this->client->followRedirect();
        self::assertSame('Draft the care sheet of a new species', $crawler->filter('form.chat-form input[name="text"]')->attr('value'));
    }
}
