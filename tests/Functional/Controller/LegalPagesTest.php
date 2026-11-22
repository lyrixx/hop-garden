<?php

namespace App\Tests\Functional\Controller;

use App\Locale\Model\SupportedLocale;
use App\Locale\PreferredLocale;
use Doctrine\ORM\EntityManagerInterface;
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
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[DataProvider('provideThePagesCases')]
    public function testThePages(string $url, string $locale, string $title, string $toComplete): void
    {
        self::getContainer()->get(PreferredLocale::class)->set(SupportedLocale::from($locale));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

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

    #[TestWith(['/'])]
    #[TestWith(['/assistant'])]
    #[TestWith(['/garden'])]
    #[TestWith(['/notifications'])]
    #[TestWith(['/privacy'])]
    public function testTheFooterLinksToThemOnEveryPage(string $url): void
    {
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
     * What the privacy policy says of the cookies: the session one only, whatever the page.
     */
    public function testThePrivacyPolicyNamesTheOnlyCookie(): void
    {
        foreach (['/', '/assistant', '/garden', '/plants', '/legal-notice'] as $url) {
            $this->client->request('GET', $url);
        }
        $crawler = $this->client->request('GET', '/privacy');

        $session = $this->client->getRequest()->getSession()->getName();
        self::assertSame([$session], array_map(static fn (Cookie $cookie): string => $cookie->getName(), $this->client->getCookieJar()->all()));
        self::assertStringContainsString("the session cookie ({$session})", $crawler->filter('#cookies')->text());
    }
}
