<?php

namespace App\Tests\Unit\Chat;

use App\Chat\ChatMarkdown;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class ChatMarkdownTest extends TestCase
{
    #[TestWith(['Prune it in March.', '<p>Prune it in March.</p>'])]
    #[TestWith(["Prune it.\n\nThen mulch.\n", '<p>Prune it.</p><p>Then mulch.</p>'])]
    #[TestWith(["Which one?\n1. Bigleaf\n2) Panicled\n- Smooth", '<p>Which one?</p><ol><li>Bigleaf</li><li>Panicled</li></ol><ul><li>Smooth</li></ul>'])]
    #[TestWith(['## Pruning', '<p><strong>Pruning</strong></p>'])]
    #[TestWith(['**Hardy** to *-15 °C*, see _Hydrangea_ or `propose_plant`', '<p><strong>Hardy</strong> to <em>-15 °C</em>, see <em>Hydrangea</em> or <code>propose_plant</code></p>'])]
    #[TestWith(['snake_case_name stays', '<p>snake_case_name stays</p>'])]
    public function testTheConstructsOfAnAnswer(string $markdown, string $html): void
    {
        self::assertSame($html, ChatMarkdown::toHtml($markdown));
    }

    public function testAnAnswerIsNeverTrustedAsHtml(): void
    {
        self::assertSame('<p>&lt;script&gt;alert(&quot;hi&quot;)&lt;/script&gt; <code>&lt;b&gt;</code></p>', ChatMarkdown::toHtml('<script>alert("hi")</script> `<b>`'));
    }
}
