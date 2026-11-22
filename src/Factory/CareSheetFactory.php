<?php

namespace App\Factory;

use App\Plant\Entity\CareSheet;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\Confidence;
use App\Plant\Model\Exposure;
use App\Plant\Model\Source;
use App\Plant\Model\Watering;
use Zenstruck\Foundry\ObjectFactory;

/**
 * An embeddable of Plant, never persisted on its own. Backed by Trefle, with a hardiness to review.
 *
 * @extends ObjectFactory<CareSheet>
 */
final class CareSheetFactory extends ObjectFactory
{
    public static function class(): string
    {
        return CareSheet::class;
    }

    /**
     * The same confidence for every field.
     */
    public function withConfidence(Confidence $confidence): static
    {
        return $this->with(['confidence' => self::confidence($confidence)]);
    }

    protected function defaults(): array
    {
        return [
            'minTemperatureC' => -15,
            'frostSensitive' => false,
            'exposure' => Exposure::PartShade,
            'watering' => Watering::High,
            'pruningMonths' => [3],
            'deadheadingMonths' => [7, 8],
            'cuttingMonths' => [6],
            'pruningAdvice' => 'Cut the faded flower heads back to the first pair of buds.',
            'cuttingAdvice' => 'Take softwood cuttings of 10 cm.',
            'confidence' => [...self::confidence(Confidence::High), CareSheetField::MinTemperatureC->value => Confidence::Low],
            'sources' => [new Source('Trefle'), new Source(Source::LLM_KNOWLEDGE)],
        ];
    }

    /**
     * @return array<string, Confidence>
     */
    private static function confidence(Confidence $confidence): array
    {
        return array_fill_keys(CareSheetField::values(), $confidence);
    }
}
