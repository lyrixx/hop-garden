<?php

namespace App\Plant;

use App\Plant\Entity\CareSheet;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\CareSheetRow;

/**
 * Human-readable values of a care sheet, shared by the web card and the MCP Markdown resource.
 */
final readonly class CareSheetPresenter
{
    /**
     * @return list<CareSheetRow>
     */
    public function rows(CareSheet $careSheet): array
    {
        $rows = [];
        foreach (CareSheetField::cases() as $field) {
            $rows[] = new CareSheetRow($field, $this->valueOf($careSheet, $field), $careSheet->getConfidenceOf($field));
        }

        return $rows;
    }

    public function valueOf(CareSheet $careSheet, CareSheetField $field): string
    {
        return match ($field) {
            CareSheetField::MinTemperatureC => \sprintf('%d °C', $careSheet->getMinTemperatureC()),
            CareSheetField::FrostSensitive => $careSheet->isFrostSensitive() ? 'Yes' : 'No',
            CareSheetField::Exposure => $careSheet->getExposure()->label(),
            CareSheetField::Watering => $careSheet->getWatering()->label(),
            CareSheetField::PruningMonths => $this->months($careSheet->getPruningMonths()),
            CareSheetField::DeadheadingMonths => $this->months($careSheet->getDeadheadingMonths()),
            CareSheetField::CuttingMonths => $this->months($careSheet->getCuttingMonths()),
            CareSheetField::PruningAdvice => $this->text($careSheet->getPruningAdvice()),
            CareSheetField::CuttingAdvice => $this->text($careSheet->getCuttingAdvice()),
        };
    }

    public static function monthName(int $month): string
    {
        $date = \DateTimeImmutable::createFromFormat('!n', (string) $month);
        if (false === $date) {
            throw new \LogicException(\sprintf('Invalid month %d.', $month));
        }

        return $date->format('F');
    }

    /**
     * On one line: the value lands in a table cell.
     */
    private function text(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return '' !== $text ? $text : '—';
    }

    /**
     * @param list<int> $months
     */
    private function months(array $months): string
    {
        if ([] === $months) {
            return '—';
        }

        return implode(', ', array_map(self::monthName(...), $months));
    }
}
