<?php

namespace App\Plant\Model;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The fields of a care sheet, named as the MCP tools and the confidence map name them.
 */
enum CareSheetField: string implements TranslatableInterface
{
    case MinTemperatureC = 'min_temperature_c';
    case FrostSensitive = 'frost_sensitive';
    case Exposure = 'exposure';
    case Watering = 'watering';
    case PruningMonths = 'pruning_months';
    case DeadheadingMonths = 'deadheading_months';
    case CuttingMonths = 'cutting_months';
    case PruningAdvice = 'pruning_advice';
    case CuttingAdvice = 'cutting_advice';

    /**
     * Its label, in translations/messages+intl-icu.*.yaml.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('care_sheet_field.' . $this->value, locale: $locale);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $field): string => $field->value, self::cases());
    }
}
