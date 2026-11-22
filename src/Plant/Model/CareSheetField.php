<?php

namespace App\Plant\Model;

/**
 * The fields of a care sheet, named as the MCP tools and the confidence map name them.
 */
enum CareSheetField: string
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

    public function label(): string
    {
        return match ($this) {
            self::MinTemperatureC => 'Hardiness',
            self::FrostSensitive => 'Frost sensitive',
            self::Exposure => 'Exposure',
            self::Watering => 'Watering',
            self::PruningMonths => 'Pruning',
            self::DeadheadingMonths => 'Deadheading',
            self::CuttingMonths => 'Cuttings',
            self::PruningAdvice => 'Pruning advice',
            self::CuttingAdvice => 'Cutting advice',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $field): string => $field->value, self::cases());
    }
}
