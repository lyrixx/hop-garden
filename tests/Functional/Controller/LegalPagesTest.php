<?php

namespace App\Tests\Functional\Controller;

use App\Factory\UserFactory;
use App\Locale\UserPreferredLocale;
use App\Tests\Utils\Garden\GardenPathsTrait;
use App\Tests\Utils\Security\LogInTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The legal notice, the terms of sale and the privacy policy: public, in every language, with the blanks of the
 * publisher shown as such, and linked from the footer of every page.
 */
final class LegalPagesTest extends WebTestCase
{
    use GardenPathsTrait;
    use LogInTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[DataProvider('provideThePagesCases')]
    public function testThePages(string $url, string $locale, string $title, string $toComplete): void
    {
        $this->client->getCookieJar()->set(new Cookie(UserPreferredLocale::COOKIE, $locale));

        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSame($locale, $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextSame('h1', $title);
        self::assertSelectorExists('.public-nav', 'A public page.');
        $blanks = $crawler->filter('.legal-card .to-complete');
        self::assertGreaterThan(1, $blanks->count(), 'What the publisher has to fill in.');
        foreach ($blanks as $blank) {
            self::assertStringStartsWith($toComplete . ' ', trim($blank->textContent));
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function provideThePagesCases(): iterable
    {
        yield 'legal notice' => ['/legal-notice', 'en', 'Legal notice', 'To be completed'];
        yield 'mentions légales' => ['/legal-notice', 'fr', 'Mentions légales', 'À compléter'];
        yield 'terms of sale' => ['/terms', 'en', 'Terms of sale', 'To be completed'];
        yield 'conditions générales de vente' => ['/terms', 'fr', 'Conditions générales de vente', 'À compléter'];
        yield 'privacy policy' => ['/privacy', 'en', 'Privacy policy', 'To be completed'];
        yield 'politique de confidentialité' => ['/privacy', 'fr', 'Politique de confidentialité', 'À compléter'];
    }

    #[TestWith(['/', false])]
    #[TestWith(['/privacy', false])]
    #[TestWith(['/login', false])]
    #[TestWith(['/register', false])]
    #[TestWith(['/reset-password', false])]
    #[TestWith(['/', true])]
    #[TestWith(['/g/the-garden-of-the-tests/assistant', true])]
    #[TestWith(['/g/the-garden-of-the-tests/garden', true])]
    #[TestWith(['/g/the-garden-of-the-tests/notifications', true])]
    #[TestWith(['/account', true])]
    public function testTheFooterLinksToThemOnEveryPage(string $url, bool $member): void
    {
        if ($member) {
            $this->logIn($this->client);
        }
        $crawler = $this->client->request('GET', $url);

        $links = $crawler->filter('.footer .footer-links a');
        self::assertSame(
            [['Legal notice', '/legal-notice'], ['Terms of sale', '/terms'], ['Privacy policy', '/privacy']],
            $links->each(static fn (Crawler $link): array => [$link->text(), $link->attr('href')]),
        );
        foreach ($links->links() as $link) {
            $this->client->click($link);
            self::assertResponseIsSuccessful($link->getUri());
        }
    }

    /**
     * The cookies of the privacy policy are the ones Hop Garden sets: the session for a visitor, the language once
     * chosen, REMEMBERME when ticked, the subscriber cookie of the Mercure hub on a page of the garden. The double-submit
     * one of the CSRF tokens is set by JavaScript: none here.
     */
    public function testThePrivacyPolicyListsTheRealCookies(): void
    {
        $crawler = $this->client->request('GET', '/privacy');
        $session = $this->client->getRequest()->getSession()->getName();
        self::assertSame(
            [$session, 'REMEMBERME', UserPreferredLocale::COOKIE, 'mercure_access_token', '__Host-csrf-token_…'],
            $crawler->filter('#cookies dt code')->each(static fn (Crawler $name): string => $name->text()),
        );

        foreach (['/', '/legal-notice', '/terms', '/login', '/register', '/reset-password'] as $url) {
            $this->client->request('GET', $url);
        }
        self::assertSame([$session => null], $this->cookies(), 'A visitor: the session, until the browser closes.');

        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->filter('.public-nav .locale-switch')->selectButton('FR')->form());
        self::assertSame([$session => null, UserPreferredLocale::COOKIE => self::daysUntil('+1 year')], $this->cookies(), 'A language chosen.');

        $member = UserFactory::new()->approved()->create();
        $this->joinTheGardenOfTheTest($member);
        $this->forgetTheFailedLogins();
        $this->client->restart();
        $this->logInWithTheForm($this->client, $member->getEmail());
        self::assertResponseRedirects('/assistant');
        self::assertSame([$session => null], $this->cookies(), 'Logged in.');
        $this->client->followRedirect();
        self::assertResponseRedirects(self::inGarden('/assistant'), 302, 'Of the last garden.');
        $this->client->followRedirect();
        self::assertSame([$session => null, 'mercure_access_token' => null], $this->cookies(), 'A page of the garden: its live updates, until the browser closes.');

        $this->client->restart();
        $this->logInWithTheForm($this->client, $member->getEmail(), rememberMe: true);
        self::assertResponseRedirects('/assistant');
        self::assertSame([$session => null, 'REMEMBERME' => 30], $this->cookies(), 'Remembered.');

        $crawler = $this->client->request('GET', self::inGarden('/garden'));
        self::assertSame([$session => null, 'REMEMBERME' => 30, 'mercure_access_token' => null], $this->cookies());
        $this->client->submit($crawler->filter('.account-menu form')->form());
        self::assertSame([$session => null], $this->cookies(), 'Logged out: forgotten.');
    }

    /**
     * @return array<string, ?int> by name, the days each cookie lasts, null until the browser closes
     */
    private function cookies(): array
    {
        $cookies = [];
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            $expires = $cookie->getExpiresTime();
            $cookies[$cookie->getName()] = null === $expires ? null : (int) round(((int) $expires - time()) / 86400);
        }
        ksort($cookies);

        return $cookies;
    }

    private static function daysUntil(string $modifier): int
    {
        return (int) round(((int) strtotime($modifier) - time()) / 86400);
    }
}
