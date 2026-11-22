<?php

namespace App\Tests\Functional\Garden;

use App\Reminder\ReminderChecker;
use App\Tests\Utils\Garden\TwoGardens;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Bob, a member of the garden of Alice, organises it with her, in French: what he changes, Alice sees; each gets the
 * frost alert, in their language. Only its owner manages it.
 */
final class SharedGardenTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use LogInTrait;

    private KernelBrowser $client;
    private TwoGardens $gardens;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->gardens = TwoGardens::create(self::getContainer());
    }

    public function testBobOrganisesTheGardenOfAliceWithHer(): void
    {
        $this->as('bob');
        $crawler = $this->client->request('GET', '/garden');
        self::assertResponseIsSuccessful();
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextContains('main', 'Rose by the gate', 'The plants of the garden he shares.');

        // A space, a correction of a plant, a care sheet validated
        $crawler = $this->client->request('GET', '/spaces/new');
        $this->client->submit($crawler->selectButton('Ajouter l’espace')->form(), ['space[name]' => 'Coin de Bob']);
        self::assertResponseRedirects();
        $crawler = $this->client->request('GET', '/garden/' . $this->gardens->gate->getId() . '/edit');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(), ['planting[diseases]' => 'Des pucerons, vus par Bob.']);
        self::assertResponseRedirects('/garden/' . $this->gardens->gate->getId(), 303);
        $crawler = $this->client->request('GET', '/plants/' . $this->gardens->draft->getId());
        $this->client->submit($crawler->selectButton('Valider')->form());
        self::assertResponseRedirects('/plants/' . $this->gardens->draft->getId(), 303, 'A member validates too: the human validates.');

        $this->as('alice');
        $crawler = $this->client->request('GET', '/spaces');
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextContains('main .space-tile', 'Coin de Bob');
        $this->client->request('GET', '/garden/' . $this->gardens->gate->getId());
        self::assertSelectorTextContains('main', 'Des pucerons, vus par Bob.');
        $this->client->request('GET', '/plants/' . $this->gardens->draft->getId());
        self::assertSelectorNotExists('form[action$="/validate"]', 'Validated by Bob.');

        $this->as('carol');
        $crawler = $this->client->request('GET', '/spaces');
        self::assertStringNotContainsString('Coin de Bob', $crawler->filter('main')->text());
    }

    public function testTheFrostAlertComesToEachMemberInTheirLanguage(): void
    {
        self::getContainer()->get(ReminderChecker::class)->check($this->gardens->allotment, self::mockTime('2026-12-01 07:00')->now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->as('alice');
        $crawler = $this->client->request('GET', '/notifications');
        self::assertSame(
            ['Frost expected on Tuesday 1 December (-3.2 °C): bring Climbing rose indoors. Down to -12.4 °C on Thursday 3 December.', 'Time to prune Climbing rose.'],
            $crawler->filter('.notification')->each(static fn (Crawler $notification): string => $notification->filter('p')->first()->text()),
        );

        $this->as('bob');
        $crawler = $this->client->request('GET', '/notifications');
        self::assertSame(
            ['Gel annoncé le mardi 1 décembre (-3,2 °C) : rentrez Climbing rose à l’abri. Jusqu’à -12,4 °C le jeudi 3 décembre.', 'Time to prune Climbing rose.'],
            $crawler->filter('.notification')->each(static fn (Crawler $notification): string => str_replace(["\u{a0}", "\u{202f}"], ' ', $notification->filter('p')->first()->text())),
            'His own, in French.',
        );

        $this->as('carol');
        $crawler = $this->client->request('GET', '/notifications');
        self::assertCount(0, $crawler->filter('.notification'));
    }

    public function testOnlyItsOwnerManagesIt(): void
    {
        $settings = '/gardens/' . $this->gardens->allotment->getId() . '/settings';

        $this->as('bob');
        $this->client->request('GET', $settings);
        self::assertResponseStatusCodeSame(404, 'A member is no owner.');
        $crawler = $this->client->request('GET', '/garden');
        self::assertCount(0, $crawler->filter('.account-menu a[href="' . $settings . '"]'));

        $this->as('alice');
        $crawler = $this->client->request('GET', $settings);
        self::assertResponseIsSuccessful();
        self::assertSame(['Alice · Owner', 'Bob · Member'], $crawler->filter('.garden-members li')->each(static fn (Crawler $member): string => $member->text()));
        $this->client->submit($crawler->filter('form.garden-place-form')->form(), ['garden[name]' => 'Our allotment']);
        self::assertResponseRedirects($settings, 303);
        $this->client->followRedirect();
        self::assertSelectorTextSame('h1', 'Our allotment');
        self::assertSelectorTextContains('main', 'Lyon, Auvergne-Rhône-Alpes, France', 'Its place kept: no city searched.');
    }

    private function as(string $member): void
    {
        $this->client->getCookieJar()->clear();
        $this->logIn($this->client, match ($member) {
            'alice' => $this->gardens->alice,
            'bob' => $this->gardens->bob,
            'carol' => $this->gardens->carol,
        });
    }
}
