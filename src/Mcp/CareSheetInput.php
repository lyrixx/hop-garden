<?php

namespace App\Mcp;

use App\Plant\Entity\CareSheet;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\Confidence;
use App\Plant\Model\Exposure;
use App\Plant\Model\Source;
use App\Plant\Model\Watering;

/**
 * The "care_sheet" argument of propose_plant: its JSON schema, and its mapping to a CareSheet.
 */
final class CareSheetInput
{
    public const array SCHEMA = [
        'type' => 'object',
        'description' => 'The care sheet, for a temperate Western European climate. Every field is required.',
        'additionalProperties' => false,
        'properties' => [
            CareSheetField::MinTemperatureC->value => [
                'type' => 'integer',
                'minimum' => -60,
                'maximum' => 30,
                'description' => 'Hardiness: lowest temperature in °C an established plant survives outdoors. Example: -15.',
            ],
            CareSheetField::FrostSensitive->value => [
                'type' => 'boolean',
                'description' => 'true when any frost damages the plant (it must be covered or brought in below 0 °C).',
            ],
            CareSheetField::Exposure->value => [
                'type' => 'string',
                'enum' => ['sun', 'part_shade', 'shade'],
                'description' => 'Best exposure.',
            ],
            CareSheetField::Watering->value => [
                'type' => 'string',
                'enum' => ['low', 'medium', 'high'],
                'description' => 'Water needs once established.',
            ],
            CareSheetField::PruningMonths->value => self::MONTHS + ['description' => 'Months to prune, 1 = January. [] when the plant needs no pruning.'],
            CareSheetField::DeadheadingMonths->value => self::MONTHS + ['description' => 'Months to cut the faded flowers. [] when not relevant.'],
            CareSheetField::CuttingMonths->value => self::MONTHS + ['description' => 'Months to take cuttings. [] when the plant is not propagated by cuttings.'],
            CareSheetField::PruningAdvice->value => [
                'type' => 'string',
                'maxLength' => 500,
                'description' => 'One or two sentences, in the language of the common name: how to prune. Example: "Cut last year\'s stems back to two pairs of buds."',
            ],
            CareSheetField::CuttingAdvice->value => [
                'type' => 'string',
                'maxLength' => 500,
                'description' => 'One or two sentences, in the language of the common name: how to take cuttings. Empty string when not relevant.',
            ],
            'confidence' => [
                'type' => 'object',
                'description' => 'Your honest confidence for each field above: "high" only for well-established horticultural rules, "low" for any uncertain number. The user reviews the low and medium ones.',
                'additionalProperties' => false,
                'properties' => [
                    CareSheetField::MinTemperatureC->value => self::CONFIDENCE,
                    CareSheetField::FrostSensitive->value => self::CONFIDENCE,
                    CareSheetField::Exposure->value => self::CONFIDENCE,
                    CareSheetField::Watering->value => self::CONFIDENCE,
                    CareSheetField::PruningMonths->value => self::CONFIDENCE,
                    CareSheetField::DeadheadingMonths->value => self::CONFIDENCE,
                    CareSheetField::CuttingMonths->value => self::CONFIDENCE,
                    CareSheetField::PruningAdvice->value => self::CONFIDENCE,
                    CareSheetField::CuttingAdvice->value => self::CONFIDENCE,
                ],
                'required' => [
                    CareSheetField::MinTemperatureC->value,
                    CareSheetField::FrostSensitive->value,
                    CareSheetField::Exposure->value,
                    CareSheetField::Watering->value,
                    CareSheetField::PruningMonths->value,
                    CareSheetField::DeadheadingMonths->value,
                    CareSheetField::CuttingMonths->value,
                    CareSheetField::PruningAdvice->value,
                    CareSheetField::CuttingAdvice->value,
                ],
            ],
            'sources' => [
                'type' => 'array',
                'minItems' => 1,
                'description' => 'Where the values come from: {"name": "Trefle"} when search_plant found the species, and {"name": "LLM knowledge"} for what you filled yourself.',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                        'url' => ['type' => 'string', 'format' => 'uri', 'maxLength' => 2048],
                    ],
                    'required' => ['name'],
                ],
            ],
        ],
        'required' => [
            CareSheetField::MinTemperatureC->value,
            CareSheetField::FrostSensitive->value,
            CareSheetField::Exposure->value,
            CareSheetField::Watering->value,
            CareSheetField::PruningMonths->value,
            CareSheetField::DeadheadingMonths->value,
            CareSheetField::CuttingMonths->value,
            CareSheetField::PruningAdvice->value,
            CareSheetField::CuttingAdvice->value,
            'confidence',
            'sources',
        ],
    ];
    private const array MONTHS = [
        'type' => 'array',
        'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
        'uniqueItems' => true,
    ];

    private const array CONFIDENCE = ['type' => 'string', 'enum' => ['high', 'medium', 'low']];

    /**
     * Trefle gives the names of a species only: every care value comes from the model, so "LLM knowledge" is always
     * among the sources, even when the model does not list it.
     *
     * @param array<string, mixed> $input validated against SCHEMA by the MCP SDK
     */
    public static function toCareSheet(array $input): CareSheet
    {
        /** @var array{min_temperature_c: int, frost_sensitive: bool, exposure: string, watering: string, pruning_months: list<int>, deadheading_months: list<int>, cutting_months: list<int>, pruning_advice: string, cutting_advice: string, confidence: array<string, string>, sources: list<array{name: string, url?: string}>} $careSheet */
        $careSheet = $input;

        $sources = array_map(static fn (array $source): Source => new Source($source['name'], $source['url'] ?? null), $careSheet['sources']);
        if ([] === array_filter($sources, static fn (Source $source): bool => $source->isLlmKnowledge())) {
            $sources[] = new Source(Source::LLM_KNOWLEDGE);
        }

        return new CareSheet(
            $careSheet['min_temperature_c'],
            $careSheet['frost_sensitive'],
            Exposure::from($careSheet['exposure']),
            Watering::from($careSheet['watering']),
            $careSheet['pruning_months'],
            $careSheet['deadheading_months'],
            $careSheet['cutting_months'],
            trim($careSheet['pruning_advice']),
            trim($careSheet['cutting_advice']),
            array_map(Confidence::from(...), $careSheet['confidence']),
            $sources,
        );
    }
}
