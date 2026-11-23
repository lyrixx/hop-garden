<?php

namespace App\DataSource\OpenMeteo\Model;

/**
 * A search of places split into its postcode and its name, in any order: "89100 Paron", "Paron 89100", "Paron (89100)",
 * "London SW1A 1AA". Open-Meteo finds a place by its name or by its postcode, never by both at once. A word with a
 * digit is taken for the postcode, or a part of it.
 */
final readonly class PlaceQuery
{
    private const string POSTCODE_WORD = '/(?<![^\s,()])[^\s,()]*\d[^\s,()]*(?![^\s,()])/u';

    private function __construct(
        /**
         * "Paron", or "Paron, France": Open-Meteo reads what follows a comma as a country or a region.
         */
        public ?string $name,
        public ?string $postcode,
    ) {
    }

    /**
     * @param string $query in UTF-8: GeocodingClient::search() cuts it with mb_substr(), which replaces any invalid byte
     */
    public static function parse(string $query): self
    {
        preg_match_all(self::POSTCODE_WORD, $query, $matches);
        $postcode = implode(' ', $matches[0]);

        $rest = (string) preg_replace([self::POSTCODE_WORD, '/[()]/'], ' ', $query);
        $parts = array_filter(
            array_map(static fn (string $part): string => trim((string) preg_replace('/\s+/u', ' ', $part)), explode(',', $rest)),
            static fn (string $part): bool => '' !== $part,
        );
        $name = implode(', ', $parts);

        return new self('' !== $name ? $name : null, '' !== $postcode ? $postcode : null);
    }
}
