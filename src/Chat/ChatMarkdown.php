<?php

namespace App\Chat;

/**
 * The few Markdown constructs a chat answer uses: paragraphs, lists, bold, italic, inline code. Everything else is
 * escaped: the answer of a model is never trusted as HTML.
 */
final class ChatMarkdown
{
    public static function toHtml(string $source): string
    {
        $html = [];
        $list = null;

        foreach (explode("\n", $source) as $rawLine) {
            $line = rtrim($rawLine);
            $type = match (true) {
                1 === preg_match('/^\s*[-*]\s+(.*)$/', $line, $matches) => 'ul',
                1 === preg_match('/^\s*\d+[.)]\s+(.*)$/', $line, $matches) => 'ol',
                default => null,
            };

            if (null !== $type) {
                if ($list !== $type) {
                    if (null !== $list) {
                        $html[] = '</' . $list . '>';
                    }
                    $html[] = '<' . $type . '>';
                    $list = $type;
                }
                $html[] = '<li>' . self::inline($matches[1]) . '</li>';

                continue;
            }

            if (null !== $list) {
                $html[] = '</' . $list . '>';
                $list = null;
            }
            if ('' === trim($line)) {
                continue;
            }
            $html[] = 1 === preg_match('/^#{1,6}\s+(.*)$/', $line, $heading)
                ? '<p><strong>' . self::inline($heading[1]) . '</strong></p>'
                : '<p>' . self::inline($line) . '</p>';
        }
        if (null !== $list) {
            $html[] = '</' . $list . '>';
        }

        return implode('', $html);
    }

    private static function inline(string $text): string
    {
        // No "u" flag: \w would match every letter, the ASCII word characters only are meant
        return (string) preg_replace(
            ['/`([^`]+)`/', '/\*\*([^*]+)\*\*/', '/(^|[^*])\*([^*\s][^*]*)\*/', '/(^|[^_A-Za-z0-9])_([^_\s][^_]*)_/'],
            ['<code>$1</code>', '<strong>$1</strong>', '$1<em>$2</em>', '$1<em>$2</em>'],
            htmlspecialchars($text, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }
}
