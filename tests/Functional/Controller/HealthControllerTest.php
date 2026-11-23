<?php

namespace App\Tests\Functional\Controller;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The probe, /healthz: a 200 as long as PHP answers, and nothing left behind, neither a session nor a cookie, without
 * the database.
 */
final class HealthControllerTest extends WebTestCase
{
    public function testTheProbeAnswersWithoutSessionCookieNorQuery(): void
    {
        $client = self::createClient();
        $client->enableProfiler();

        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        self::assertSame("OK\n", $client->getResponse()->getContent());
        self::assertSame([], $client->getResponse()->headers->getCookies());
        // A session that started has an id, even once saved: a request without cookie gets one only by starting it
        self::assertSame('', $client->getRequest()->getSession()->getId(), 'No session started.');

        $profile = $client->getProfile();
        self::assertNotFalse($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        self::assertSame(0, $collector->getQueryCount());
    }
}
