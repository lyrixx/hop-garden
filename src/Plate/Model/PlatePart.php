<?php

namespace App\Plate\Model;

use App\Plant\Model\CareSheetField;
use App\Reminder\Model\ReminderType;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The part of the plant a figure of the plate points at, and the care sheet fields it explains.
 */
enum PlatePart: string implements TranslatableInterface
{
    case Bud = 'bud';
    case Leaf = 'leaf';
    case Stem = 'stem';
    case Flower = 'flower';
    case Shoot = 'shoot';

    /**
     * Its label, in translations/messages+intl-icu.*.yaml.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('plate_part.' . $this->value, locale: $locale);
    }

    public function latinLabel(): string
    {
        return match ($this) {
            self::Bud => 'Gemma',
            self::Leaf => 'Folium',
            self::Stem => 'Caulis',
            self::Flower => 'Flos',
            self::Shoot => 'Surculus',
        };
    }

    /**
     * The dormant bud bears the frost, the leaf the sun and the water, the stem is pruned, the faded flower
     * deadheaded, the young shoot taken as a cutting.
     *
     * @return list<CareSheetField>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Bud => [CareSheetField::MinTemperatureC, CareSheetField::FrostSensitive],
            self::Leaf => [CareSheetField::Exposure, CareSheetField::Watering],
            self::Stem => [CareSheetField::PruningMonths, CareSheetField::PruningAdvice],
            self::Flower => [CareSheetField::DeadheadingMonths],
            self::Shoot => [CareSheetField::CuttingMonths, CareSheetField::CuttingAdvice],
        };
    }

    /**
     * The Icon of the part: the frost the bud bears, the sun of the leaf, the secateurs of the stem.
     */
    public function pictogram(): string
    {
        return match ($this) {
            self::Bud => 'snowflake',
            self::Leaf => 'sun',
            self::Stem => 'secateurs',
            self::Flower => 'flower',
            self::Shoot => 'sprout',
        };
    }

    public function reminderType(): ?ReminderType
    {
        return match ($this) {
            self::Bud => ReminderType::Frost,
            self::Leaf => null,
            self::Stem => ReminderType::Pruning,
            self::Flower => ReminderType::Deadheading,
            self::Shoot => ReminderType::Cutting,
        };
    }
}
