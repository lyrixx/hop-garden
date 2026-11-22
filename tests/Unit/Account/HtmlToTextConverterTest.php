<?php

namespace App\Tests\Unit\Account;

use App\Account\HtmlToTextConverter;
use PHPUnit\Framework\TestCase;

final class HtmlToTextConverterTest extends TestCase
{
    public function testTheEntitiesOfTwigAreDecodedOnceAfterTheTags(): void
    {
        $html = <<<'HTML'
            <!DOCTYPE html>
            <html>
                <head><title>Hop Garden</title><style>p { color: green; }</style></head>
                <body>
                    <p>Hello Dave O&#039;Brien &amp; co, &lt;b&gt;not bold&lt;/b&gt;, &amp;amp; voilà</p>
                    <p>Or copy this link:<br><a href="https://hop-garden.test/verify?_expiration=1&amp;_hash=x&amp;id=1">https://hop-garden.test/verify?_expiration=1&amp;_hash=x&amp;id=1</a></p>


                    <p>Thank you.</p>
                </body>
            </html>
            HTML;

        self::assertSame(<<<'TEXT'
            Hello Dave O'Brien & co, <b>not bold</b>, &amp; voilà

            Or copy this link:
            https://hop-garden.test/verify?_expiration=1&_hash=x&id=1

            Thank you.
            TEXT, new HtmlToTextConverter()->convert($html, 'utf-8'));
    }
}
