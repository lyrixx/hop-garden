<?php

namespace App\Account;

use Symfony\Component\Mime\HtmlToTextConverter\HtmlToTextConverterInterface;

/**
 * The text part of the emails, from their HTML (twig.mailer.html_to_text_converter). The default converter of Mime
 * only strips the tags: the escaping of Twig stayed, and the "&amp;" of a copied signed link broke its signature.
 * Here the tags go first, then the entities, once: an escaped "&lt;b&gt;" stays text. A <br> or a paragraph ends a
 * line, and the indentation of the templates goes.
 */
final readonly class HtmlToTextConverter implements HtmlToTextConverterInterface
{
    public function convert(string $html, string $charset): string
    {
        $html = preg_replace(['{<(head|style)\b.*?</\1>}is', '{<br\b[^>]*>}i', '{</p>}i'], ['', "\n", "\n\n"], $html) ?? throw new \RuntimeException('The HTML of the email could not be converted to text.');
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, $charset);

        // Spaces and tabs only: without the u modifier, \h would cut the 0xA0 byte out of a UTF-8 "à".
        return trim(preg_replace(['{[ \t]*\n[ \t]*}', '{\n{3,}}'], ["\n", "\n\n"], $text) ?? throw new \RuntimeException('The text of the email could not be tidied.'));
    }
}
