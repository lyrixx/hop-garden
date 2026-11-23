<?php

namespace App\DataSource\OpenMeteo\Model;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * A place found by the geocoding of Open-Meteo, with its position, its timezone and its postcodes.
 */
final readonly class Place
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $region,
        public ?string $country,
        public float $latitude,
        public float $longitude,
        public string $timezone,
        /**
         * As Open-Meteo lists them: "89100", "89090 CEDEX"... None for some places (London).
         *
         * @var list<string>
         */
        public array $postcodes = [],
        /**
         * The postcode it was found by.
         */
        public ?string $searchedPostcode = null,
    ) {
    }

    /**
     * "Lyon, Auvergne-Rhône-Alpes, France": a region named as its city once only. The name a garden keeps.
     */
    public function label(): string
    {
        return $this->describe($this->name);
    }

    /**
     * "Paron (89100), Bourgogne-Franche-Comté, France", to tell homonyms apart: with the postcode it was found by, or its
     * only one. A city of several (Paris, Lyon) shows none: one of them would stand for one of its districts.
     */
    public function suggestion(): string
    {
        $postcode = $this->searchedPostcode ?? $this->onlyPostcode();

        return $this->describe(null !== $postcode ? \sprintf('%s (%s)', $this->name, $postcode) : $this->name);
    }

    /**
     * Whatever the case and the spaces: "89100" is one of "89100", "89090 CEDEX"... (a CEDEX is a postcode of a firm).
     */
    public function hasPostcode(string $postcode): bool
    {
        $postcode = self::normalizedPostcode($postcode);

        return array_any($this->postcodes, static fn (string $its): bool => self::normalizedPostcode($its) === $postcode);
    }

    /**
     * As Open-Meteo matches a name: its beginning, whatever the case, the accents and the hyphens.
     */
    public function isNamed(string $name): bool
    {
        $slugger = new AsciiSlugger();
        $name = $slugger->slug($name)->lower()->toString();

        return '' !== $name && str_starts_with($slugger->slug($this->name)->lower()->toString(), $name);
    }

    public function foundByPostcode(string $postcode): self
    {
        return new self($this->id, $this->name, $this->region, $this->country, $this->latitude, $this->longitude, $this->timezone, $this->postcodes, $postcode);
    }

    private function describe(string $name): string
    {
        $parts = [$name];
        foreach ([$this->region, $this->country] as $part) {
            if (null !== $part && '' !== $part && $part !== $this->name && !\in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }

        return implode(', ', $parts);
    }

    private function onlyPostcode(): ?string
    {
        $postcodes = array_values(array_unique(array_filter($this->postcodes, static fn (string $postcode): bool => !self::isCedex($postcode))));

        return 1 === \count($postcodes) ? $postcodes[0] : null;
    }

    private static function normalizedPostcode(string $postcode): string
    {
        return str_replace(' ', '', mb_strtoupper((string) preg_replace('/\s+CEDEX\b.*$/i', '', trim($postcode))));
    }

    private static function isCedex(string $postcode): bool
    {
        return 1 === preg_match('/\bCEDEX\b/i', $postcode);
    }
}
