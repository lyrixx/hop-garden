<?php

namespace App\Plant;

use App\Locale\IntlFormatter;
use App\Plant\Entity\CareSheet;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\CareSheetRow;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Human-readable values of a care sheet, shared by the web card and the MCP Markdown resource, in the language of the
 * translator.
 */
final readonly class CareSheetPresenter
{
    public function __construct(
        private TranslatorInterface $translator,
        private IntlFormatter $formatter,
    ) {
    }

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
            CareSheetField::MinTemperatureC => $this->translator->trans('unit.celsius', ['temperature' => $careSheet->getMinTemperatureC()]),
            CareSheetField::FrostSensitive => $this->translator->trans($careSheet->isFrostSensitive() ? 'answer.yes' : 'answer.no'),
            CareSheetField::Exposure => $careSheet->getExposure()->trans($this->translator),
            CareSheetField::Watering => $careSheet->getWatering()->trans($this->translator),
            CareSheetField::PruningMonths => $this->months($careSheet->getPruningMonths()),
            CareSheetField::DeadheadingMonths => $this->months($careSheet->getDeadheadingMonths()),
            CareSheetField::CuttingMonths => $this->months($careSheet->getCuttingMonths()),
            CareSheetField::PruningAdvice => $this->text($careSheet->getPruningAdvice()),
            CareSheetField::CuttingAdvice => $this->text($careSheet->getCuttingAdvice()),
        };
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

        return implode(', ', array_map($this->formatter->monthName(...), $months));
    }
}
