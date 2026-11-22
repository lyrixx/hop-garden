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
        return self::buttonsOf($email)[$button] ?? self::fail('No such button in the email.');
    }

    /**
     * The URLs its text part writes out, the ones a reader copies: those of its buttons, in their order, and no HTML
     * entity left (an "&amp;" in a signed link breaks its signature).
     *
     * @return list<string>
     */
    private static function textLinksOf(TemplatedEmail $email): array
    {
        $text = (string) $email->getTextBody();
        self::assertDoesNotMatchRegularExpression('{&(?:#\d+|#x[\da-f]+|[a-z]+);}i', $text, 'No HTML entity in the text part.');
        preg_match_all('{https?://\S+}', $text, $links);
        self::assertSame(self::buttonsOf($email), $links[0], 'The text part writes out the URL of each button.');

        return $links[0];
    }

    /**
     * @return list<string>
     */
    private static function buttonsOf(TemplatedEmail $email): array
    {
        return new Crawler((string) $email->getHtmlBody())->filter('a[style*="background"]')->each(static fn (Crawler $button): string => $button->attr('href') ?? self::fail('A button without a URL.'));
    }
}
