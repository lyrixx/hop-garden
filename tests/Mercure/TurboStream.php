<?php

namespace App\Tests\Mercure;

use Symfony\Component\DomCrawler\Crawler;

/**
 * A <turbo-stream> element of a Mercure update, as the browser receives it.
 */
final readonly class TurboStream
{
    private function __construct(
        public string $action,
        public ?string $target,
        public ?string $targets,
        public Crawler $content,
    ) {
    }

    /**
     * @return list<self>
     */
    public static function parseAll(string $html): array
    {
        preg_match_all('#<turbo-stream ([^>]*)>\s*(?:<template>(.*?)</template>\s*)?</turbo-stream>#s', $html, $matches, PREG_SET_ORDER);

        return array_map(static function (array $match): self {
            preg_match_all('/([a-z-]+)="([^"]*)"/', $match[1], $attributes, PREG_PATTERN_ORDER);
            $attributes = array_combine($attributes[1], array_map(htmlspecialchars_decode(...), $attributes[2]));

            return new self(
                $attributes['action'] ?? throw new \LogicException('A Turbo Stream has an action.'),
                $attributes['target'] ?? null,
                $attributes['targets'] ?? null,
                new Crawler('<body>' . ($match[2] ?? '') . '</body>'),
            );
        }, $matches);
    }

    /**
     * The first element of the template.
     */
    public function element(): Crawler
    {
        return $this->content->filter('body > *')->first();
    }
}
