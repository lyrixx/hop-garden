<?php

namespace App\Tests\Functional\Locale;

use App\Factory\UserFactory;
use App\Locale\Model\SupportedLocale;
use App\Locale\UserPreferredLocale;
use App\Settings\Entity\Settings;
use App\Settings\SettingsRepository;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Without account, the language of a visitor stays in a cookie: never in the settings of the application, which every
 * visitor and the reminder check share.
 */
final class LanguageOfAVisitorTest extends WebTestCase
{
    use LogInTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAVisitorChoosesInACookie(): void
    {
        $crawler = $this->client->request('GET', '/login', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR']);
        self::assertSame('Hop Garden existe en français', $crawler->filter('.locale-banner p')->text());

        $this->client->submit($crawler->selectButton('Passer en français')->form());

        self::assertResponseRedirects('/login', 303);
        self::assertSame('fr', $this->client->getCookieJar()->get(UserPreferredLocale::COOKIE)?->getValue());
        $crawler = $this->client->followRedirect();
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextSame('h1', 'Connexion');
        self::assertSelectorNotExists('.locale-banner', 'Chosen.');
        self::assertNull(self::getContainer()->get(SettingsRepository::class)->findTheSettings(), 'Not for the others.');
    }

    /**
     * Before the accounts, the banner of the public page changed the language of every visitor and of the reminder
     * check: it wrote the settings of the application.
     */
    public function testTheChoiceOfAVisitorChangesNothingForTheOthers(): void
    {
        $settings = new Settings();
        $settings->choosePreferredLocale(SupportedLocale::English);
        self::getContainer()->get(SettingsRepository::class)->add($settings);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR']);
        $this->client->submit($crawler->selectButton('Passer en français')->form());
        $this->client->followRedirect();
        self::assertSelectorTextSame('h1', 'Savoir quand tailler. Être prévenu avant le gel.');

        self::assertSame(SupportedLocale::English, self::getContainer()->get(SettingsRepository::class)->findTheSettings()?->getPreferredLocale(), 'Not the application.');
        $this->client->restart();
        $crawler = $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR']);
        self::assertSame('en', $crawler->filter('html')->attr('lang'), 'Nor another visitor.');
        self::assertSame('Hop Garden existe en français', $crawler->filter('.locale-banner p')->text(), 'Who still has the choice.');
    }

    public function testAVisitorWhoDidNotChooseGetsTheLanguageOfTheApplication(): void
    {
        $settings = new Settings();
        $settings->choosePreferredLocale(SupportedLocale::French);
        self::getContainer()->get(SettingsRepository::class)->add($settings);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->request('GET', '/register');
        self::assertSelectorTextSame('h1', 'Rejoindre Hop Garden');

        $this->client->getCookieJar()->set(new Cookie(UserPreferredLocale::COOKIE, 'en'));
        $this->client->request('GET', '/register');
        self::assertSelectorTextSame('h1', 'Join Hop Garden', 'Their choice first.');
    }

    public function testAnUnknownCookieIsIgnored(): void
    {
        $this->client->getCookieJar()->set(new Cookie(UserPreferredLocale::COOKIE, 'de'));

        $crawler = $this->client->request('GET', '/login');

        self::assertSame('en', $crawler->filter('html')->attr('lang'));
    }

    public function testTheAccountWinsOverTheCookieAndTheCookieKeepsTheLastChoice(): void
    {
        $this->client->getCookieJar()->set(new Cookie(UserPreferredLocale::COOKIE, 'fr'));
        $this->logIn($this->client, UserFactory::new()->approved()->create(['preferredLocale' => SupportedLocale::English]));

        $crawler = $this->client->request('GET', '/spaces');
        self::assertSame('en', $crawler->filter('html')->attr('lang'), 'The account first.');

        $this->client->submit($crawler->filter('.locale-switch')->selectButton('Français')->form());
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('.account-menu form')->form());

        $crawler = $this->client->request('GET', '/login');
        self::assertSame('fr', $crawler->filter('html')->attr('lang'), 'Logged out, the login page keeps it.');
    }
}
