<?php

namespace App\Tests\Utils\Mailer;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The emails of App\Account\AccountMailer, as the last request sent them (MAILER_DSN=null://null in the tests).
 */
trait SentEmailsTrait
{
    private static function lastEmail(): TemplatedEmail
    {
        $email = self::getMailerMessage();

        return $email instanceof TemplatedEmail ? $email : self::fail('No email was sent.');
    }

    /**
     * The URL of one of its buttons, the first by default.
     */
    private static function linkOf(TemplatedEmail $email, int $button = 0): string
    {
        return new Crawler((string) $email->getHtmlBody())->filter('a[style*="background"]')->eq($button)->attr('href') ?? self::fail('No such button in the email.');
    }
}
