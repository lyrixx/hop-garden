<?php

namespace App\Plant\Form\Model;

use App\Plant\Model\CareSheet;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\Confidence;
use App\Plant\Model\Exposure;
use App\Plant\Model\Plant;
use App\Plant\Model\Source;
use App\Plant\Model\Watering;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the user reviews before validating a draft, or corrects afterwards. A value they change is theirs: high
 * confidence.
 */
final class CareSheetFormData
{
    public const string USER_SOURCE = 'You';

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $commonName = '';

    /**
     * Null when the field is sent blank: refused by the validation, not by a type error.
     */
    #[Assert\NotNull]
    #[Assert\Range(min: -60, max: 30)]
    public ?int $minTemperatureC = 0;

    public bool $frostSensitive = false;

    #[Assert\NotNull]
    public ?Exposure $exposure = Exposure::Sun;

    #[Assert\NotNull]
    public ?Watering $watering = Watering::Medium;

    /** @var list<int> */
    #[Assert\All([new Assert\Range(min: 1, max: 12)])]
    public array $pruningMonths = [];

    /** @var list<int> */
    #[Assert\All([new Assert\Range(min: 1, max: 12)])]
    public array $deadheadingMonths = [];

    /** @var list<int> */
    #[Assert\All([new Assert\Range(min: 1, max: 12)])]
    public array $cuttingMonths = [];

    #[Assert\Length(max: 500)]
    public ?string $pruningAdvice = null;

    #[Assert\Length(max: 500)]
    public ?string $cuttingAdvice = null;

    #[Assert\Length(max: 1000)]
    public ?string $notes = null;

    public static function fromPlant(Plant $plant): self
    {
        $careSheet = $plant->getCareSheet();

        $data = new self();
        $data->commonName = $plant->getCommonName();
        $data->minTemperatureC = $careSheet->getMinTemperatureC();
        $data->frostSensitive = $careSheet->isFrostSensitive();
        $data->exposure = $careSheet->getExposure();
        $data->watering = $careSheet->getWatering();
        $data->pruningMonths = $careSheet->getPruningMonths();
        $data->deadheadingMonths = $careSheet->getDeadheadingMonths();
        $data->cuttingMonths = $careSheet->getCuttingMonths();
        $data->pruningAdvice = $careSheet->getPruningAdvice();
        $data->cuttingAdvice = $careSheet->getCuttingAdvice();
        $data->notes = $plant->getNotes();

        return $data;
    }

    public function toCareSheet(CareSheet $previous): CareSheet
    {
        $minTemperatureC = $this->minTemperatureC;
        $exposure = $this->exposure;
        $watering = $this->watering;
        if (null === $minTemperatureC || null === $exposure || null === $watering) {
            throw new \LogicException('A care sheet is made from a validated form only.');
        }

        $values = [
            CareSheetField::MinTemperatureC->value => [$previous->getMinTemperatureC(), $minTemperatureC],
            CareSheetField::FrostSensitive->value => [$previous->isFrostSensitive(), $this->frostSensitive],
            CareSheetField::Exposure->value => [$previous->getExposure(), $exposure],
            CareSheetField::Watering->value => [$previous->getWatering(), $watering],
            CareSheetField::PruningMonths->value => [$previous->getPruningMonths(), self::sorted($this->pruningMonths)],
            CareSheetField::DeadheadingMonths->value => [$previous->getDeadheadingMonths(), self::sorted($this->deadheadingMonths)],
            CareSheetField::CuttingMonths->value => [$previous->getCuttingMonths(), self::sorted($this->cuttingMonths)],
            CareSheetField::PruningAdvice->value => [$previous->getPruningAdvice(), trim($this->pruningAdvice ?? '')],
            CareSheetField::CuttingAdvice->value => [$previous->getCuttingAdvice(), trim($this->cuttingAdvice ?? '')],
        ];

        $confidence = $previous->getConfidences();
        $changed = false;
        foreach ($values as $field => [$before, $after]) {
            if ($before !== $after) {
                $confidence[$field] = Confidence::High;
                $changed = true;
            }
        }

        $sources = $previous->getSources();
        if ($changed && !\in_array(self::USER_SOURCE, array_map(static fn (Source $source): string => $source->name, $sources), true)) {
            $sources[] = new Source(self::USER_SOURCE);
        }

        return new CareSheet(
            $minTemperatureC,
            $this->frostSensitive,
            $exposure,
            $watering,
            self::sorted($this->pruningMonths),
            self::sorted($this->deadheadingMonths),
            self::sorted($this->cuttingMonths),
            trim($this->pruningAdvice ?? ''),
            trim($this->cuttingAdvice ?? ''),
            $confidence,
            $sources,
        );
    }

    /**
     * @param list<int> $months
     *
     * @return list<int>
     */
    private static function sorted(array $months): array
    {
        $months = array_values(array_unique(array_map(intval(...), $months)));
        sort($months);

        return $months;
    }
}
